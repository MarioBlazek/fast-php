<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Php\Process;
use FastPhp\Rust\Cargo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand('bench', 'Time the whole workload with the enabled ports against plain PHP, and check the output is identical')]
final class BenchCommand extends Base
{
    protected function configure(): void
    {
        $this->addOption('runs', null, InputOption::VALUE_REQUIRED, 'Runs of each, interleaved', '5')
            ->addOption('no-jit', null, InputOption::VALUE_NONE, 'Compare with the JIT off');
    }

    protected function handle(InputInterface $in): int
    {
        $so = $this->project->path('build', Cargo::libName('fastphp_app'));
        if (!is_file($so)) {
            throw new \RuntimeException('No port is enabled. Run `fast-php enable <id>` first.');
        }
        $w = $this->project->workload();
        $jit = !$in->getOption('no-jit');
        $cmd = $this->php->command($this->project->abs($w['script']), $w['args'], [$so], $jit, $w['php']);
        $enabled = array_keys(array_filter($this->project->state['ports'], static fn ($p) => $p['enabled']));
        $this->io->section('Benchmarking ' . implode(', ', $enabled) . ' (JIT ' . ($jit ? 'on' : 'off') . ')');
        $this->io->writeln('  ' . Process::quote($cmd));

        $times = ['php' => [], 'native' => []];
        $out = ['php' => null, 'native' => null];
        $runs = max(1, (int) $in->getOption('runs'));
        for ($i = 0; $i < $runs; $i++) {
            foreach (['php' => ['FAST_PHP_DISABLE' => '1'], 'native' => ['FAST_PHP_DISABLE' => '0']] as $side => $env) {
                $p = Process::run($cmd, $this->project->root, $env);
                if ($p->exit !== 0) {
                    throw new \RuntimeException("The $side run failed (exit {$p->exit}):\n" . trim($p->stderr ?: $p->stdout));
                }
                $times[$side][] = $p->seconds;
                $out[$side] ??= $p->stdout;
                if ($p->stdout !== $out[$side]) {
                    $this->io->warning("The $side runs don't all print the same output; the workload isn't deterministic, so the outputs can't be compared.");
                }
            }
        }
        $same = $out['php'] === $out['native'];
        $med = array_map(static function (array $t) {
            sort($t);
            return $t[intdiv(count($t), 2)];
        }, $times);
        $speedup = $med['php'] / $med['native'];
        $this->io->table(['', 'median', 'best', 'runs'], [
            ['PHP', self::ms($med['php']), self::ms(min($times['php'])), $runs],
            ['with ports', self::ms($med['native']), self::ms(min($times['native'])), $runs],
        ]);
        $this->project->state['bench'] = ['jit' => $jit, 'php' => $med['php'], 'native' => $med['native'], 'identical' => $same, 'ports' => $enabled];
        $this->project->save();
        if (!$same) {
            $this->io->error('The output with the ports differs from plain PHP. Disable them (`fast-php disable --all`) and check the recorded cases cover this input.');
            return self::FAILURE;
        }
        $this->io->success(sprintf('%.1f× faster end to end (%s → %s), identical output.', $speedup, self::ms($med['php']), self::ms($med['native'])));
        return self::SUCCESS;
    }
}
