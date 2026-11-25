<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Analysis\Codebase;
use FastPhp\Analysis\Ranker;
use FastPhp\Php\Process;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand('scan', 'Profile a PHP script and rank the functions worth porting to Rust')]
final class ScanCommand extends Base
{
    protected function configure(): void
    {
        $this->addArgument('workload', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'The command to profile, after --: php app.php [args]')
            ->addOption('runs', null, InputOption::VALUE_REQUIRED, 'Timed runs per JIT setting (best one counts)', '1')
            ->setHelp(<<<'TXT'
                Runs the script three ways with no php.ini (-n), so Xdebug and friends can't skew it:
                JIT off, JIT on, and JIT on under fast-php's observer profiler. Then it reads the
                source of every function that ran, finds what blocks a port, and ranks the rest by
                their share of the run.

                  vendor/bin/fast-php scan -- php bin/import.php data.csv
                TXT);
    }

    protected function handle(InputInterface $in): int
    {
        $w = $this->workload($in->getArgument('workload'));
        $this->project->state['workload'] = $w;
        $runs = max(1, (int) $in->getOption('runs'));
        $script = $this->project->abs($w['script']);

        $this->io->section('Building the profiler extension');
        $runtime = $this->cargo->runtime();

        $this->io->section('Baseline (' . $this->php->version() . ', no php.ini)');
        if ($skew = $this->php->skewing()) {
            $this->io->note('Your php.ini loads ' . implode(', ', $skew) . '. fast-php leaves it out of every run; it would distort the timings.');
        }
        $best = [];
        foreach (['off' => false, 'on' => true] as $label => $jit) {
            $best[$label] = INF;
            for ($i = 0; $i < $runs; $i++) {
                $p = Process::run($this->php->command($script, $w['args'], [], $jit, $w['php']), $this->project->root);
                if ($p->exit !== 0) {
                    throw new \RuntimeException("The workload failed (exit {$p->exit}):\n" . trim($p->stderr ?: $p->stdout));
                }
                $best[$label] = min($best[$label], $p->seconds);
            }
            $this->io->writeln(sprintf('  JIT %-3s  %s', $label, self::ms($best[$label])));
        }
        $jitGain = $best['off'] / $best['on'];
        if ($jitGain >= 1.3) {
            $this->io->writeln(sprintf('  The JIT alone makes this %.1f× faster. Turn it on in production (opcache.jit=tracing) whatever you port; fast-php compares against JIT-on PHP.', $jitGain));
        }

        $this->io->section('Profiling (JIT on, Zend observer API)');
        $dir = $this->project->path('tmp', 'prof');
        @mkdir($dir, 0777, true);
        array_map('unlink', glob("$dir/prof-*.json") ?: []);
        $p = Process::run($this->php->command($script, $w['args'], [$runtime], true, $w['php']), $this->project->root, ['FASTPHP_PROF_DIR' => $dir]);
        if ($p->exit !== 0) {
            throw new \RuntimeException("The profiled run failed (exit {$p->exit}):\n" . trim($p->stderr ?: $p->stdout));
        }
        [$functions, $wall] = $this->readProfiles($dir);
        if (!$functions) {
            throw new \RuntimeException('The profiler recorded nothing. Is the extension loading? Try: ' . Process::quote($this->php->command($script, $w['args'], [$runtime])));
        }
        $this->io->writeln(sprintf('  %s with the profiler (%+.0f%% overhead)', self::ms($wall), ($wall / $best['on'] - 1) * 100));

        $files = array_values(array_unique(array_filter(array_column(array_filter($functions, static fn ($f) => !$f['internal']), 'file'), 'is_file')));
        $cb = Codebase::load($files);
        $rows = Ranker::rank($cb, $functions, $wall, $this->project->rel(...));

        $this->project->state['scan'] = [
            'php' => $this->php->version(),
            'jitOff' => $best['off'],
            'jitOn' => $best['on'],
            'profiled' => $wall,
            'functions' => $rows,
        ];
        $this->project->save();

        $this->io->section('Where the time goes');
        $table = [];
        foreach (array_slice($rows, 0, 15) as $r) {
            $verdict = $r['verdict'] === 'blocked' ? 'blocked: ' . $r['blockers'][0] . (count($r['blockers']) > 1 ? ' (+' . (count($r['blockers']) - 1) . ')' : '') : $r['verdict'];
            $table[] = [$r['name'], self::pct($r['share']), self::ms($r['own']), number_format($r['calls']), $verdict];
        }
        $this->io->table(['function', 'share', 'own time', 'calls', 'verdict'], $table);

        $picks = array_values(array_filter($rows, static fn ($r) => $r['verdict'] === 'port'));
        if (!$picks) {
            $this->io->warning('Nothing qualifies: every hot function is blocked or under ' . self::pct(Ranker::WORTH_IT) . ' of the run.');
            return self::SUCCESS;
        }
        $top = $picks[0];
        $this->io->success(sprintf('Port %s first: %s of the run%s.', $top['name'], self::pct($top['share']), $top['helpers'] ? ', including ' . implode(', ', $top['helpers']) : ''));
        $this->io->writeln("Next: <info>vendor/bin/fast-php port {$top['id']}</info>");
        return self::SUCCESS;
    }

    /**
     * @param list<string> $tokens
     * @return array{script: string, args: list<string>, php: list<string>}
     */
    private function workload(array $tokens): array
    {
        if ($tokens && preg_match('/^php[\d.]*$/', basename($tokens[0]))) {
            array_shift($tokens);
        }
        $php = [];
        while ($tokens && str_starts_with($tokens[0], '-')) {
            $opt = array_shift($tokens);
            if ($opt === '-n') {
                continue;
            }
            $php[] = $opt;
            if (in_array($opt, ['-d', '-c'], true) && $tokens) {
                $php[] = array_shift($tokens);
            }
        }
        $script = array_shift($tokens);
        if ($script === null || !is_file($this->project->abs($script))) {
            throw new \RuntimeException('Give the script to run after --, for example: fast-php scan -- php app.php');
        }
        return ['script' => $this->project->rel(realpath($this->project->abs($script))), 'args' => array_values($tokens), 'php' => $php];
    }

    /** Merges the profiles of every PHP process. @return array{0: list<array<string, mixed>>, 1: float} */
    private function readProfiles(string $dir): array
    {
        $by = [];
        $wall = 0.0;
        foreach (glob("$dir/prof-*.json") ?: [] as $file) {
            $p = json_decode((string) file_get_contents($file), true);
            if (!is_array($p)) {
                continue;
            }
            $wall += $p['wall'];
            foreach ($p['functions'] as $f) {
                $k = implode("\0", [$f['class'], $f['name'], $f['file'], $f['line']]);
                if (isset($by[$k])) {
                    foreach (['calls', 'own', 'cum'] as $n) {
                        $by[$k][$n] += $f[$n];
                    }
                } else {
                    $by[$k] = $f;
                }
            }
        }
        return [array_values($by), $wall];
    }
}
