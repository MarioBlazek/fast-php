<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Analysis\Codebase;
use FastPhp\Analysis\Ranker;
use FastPhp\Workload\CliWorkload;
use FastPhp\Workload\WebWorkload;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand('scan', 'Profile a PHP script and rank the functions worth porting to Rust')]
final class ScanCommand extends Base
{
    protected function configure(): void
    {
        $this->addArgument('workload', InputArgument::IS_ARRAY, 'The command to profile, after --: php app.php [args], or for --web: php -S localhost:8000 -t public [router.php]')
            ->addOption('web', null, InputOption::VALUE_REQUIRED, 'Profile web requests from this file (.http, a list of URLs, or .har) against php -S')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, 'php -S workers, and requests in flight at once (--web)', '1')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Replay the request file this many times per pass (--web)', '1')
            ->addOption('runs', null, InputOption::VALUE_REQUIRED, 'Timed runs per JIT setting, the best one counts (default: 1, or 3 passes for --web)')
            ->setHelp(<<<'TXT'
                Runs the workload three ways with no php.ini (-n), so Xdebug and friends can't skew it:
                JIT off, JIT on, and JIT on under fast-php's observer profiler. Then it reads the
                source of every function that ran, finds what blocks a port, and ranks the rest by
                their share of the run.

                  vendor/bin/fast-php scan -- php bin/import.php data.csv

                A web app: replay requests against PHP's built-in server, which runs the app the
                way FPM does (one request at a time per worker, opcache shared).

                  vendor/bin/fast-php scan --web requests.http -- php -S localhost:8000 -t public
                TXT);
    }

    protected function handle(InputInterface $in): int
    {
        $tokens = $in->getArgument('workload');
        $web = $in->getOption('web');
        $w = $web !== null
            ? WebWorkload::parse($tokens, $web, $this->project, (int) $in->getOption('workers'), (int) $in->getOption('repeat'))
            : CliWorkload::parse($tokens, $this->project);
        $this->project->state['workload'] = $w->toState();
        // A pass over a request file is short and noisy; a script run usually isn't.
        $runs = max(1, (int) ($in->getOption('runs') ?? ($w->isWeb() ? 3 : 1)));
        $this->io->writeln('Workload: ' . $w->describe());

        $this->io->section('Building the profiler extension');
        $runtime = $this->cargo->runtime();

        $this->io->section('Baseline (' . $this->php->version() . ', no php.ini' . ($w->isWeb() ? ', after two warm-up passes' : '') . ')');
        if ($skew = $this->php->skewing()) {
            $this->io->note('Your php.ini loads ' . implode(', ', $skew) . '. fast-php leaves it out of every run; it would distort the timings.');
        }
        $best = [];
        foreach (['off' => false, 'on' => true] as $label => $jit) {
            $r = $w->best($this->php, $this->project, [], $jit, $runs);
            $best[$label] = $r->seconds;
            $this->io->writeln(sprintf('  JIT %-3s  %s', $label, self::ms($r->seconds)) . ($r->latencies ? '  ' . self::latency($r->latencies) : ''));
        }
        $jitGain = $best['off'] / $best['on'];
        if ($jitGain >= 1.3) {
            $this->io->writeln(sprintf('  The JIT alone makes this %.1f× faster. Turn it on in production (opcache.jit=tracing) whatever you port; fast-php compares against JIT-on PHP.', $jitGain));
        }

        $this->io->section('Profiling (JIT on, Zend observer API)');
        $dir = $this->project->path('tmp', 'prof');
        @mkdir($dir, 0777, true);
        array_map('unlink', glob("$dir/prof-*.json") ?: []);
        // A web profile leaves out the warm-up passes: opcache compiling every file the first
        // time would show up as bootstrap cost.
        $warm = [];
        $p = $w->once($this->php, $this->project, [$runtime], true, [], ['FASTPHP_PROF_DIR' => $dir], function () use ($dir, &$warm) {
            $warm = $this->snapshot($dir);
        });
        [$functions, $wall, $requests] = $this->readProfiles($dir, $warm);
        if (!$functions) {
            throw new \RuntimeException('The profiler recorded nothing. Is the extension loading? Check that ' . $runtime . ' loads with: php -n -d extension=' . $runtime . ' -m');
        }
        $this->io->writeln($w->isWeb()
            ? sprintf('  %d requests served by %d PHP process%s, %s inside PHP', $requests, $this->workers($dir), $this->workers($dir) === 1 ? '' : 'es', self::ms($wall))
            : sprintf('  %s with the profiler (%+.0f%%)', self::ms($p->seconds), ($p->seconds / $best['on'] - 1) * 100));

        $files = array_values(array_unique(array_filter(array_column(array_filter($functions, static fn ($f) => !$f['internal']), 'file'), 'is_file')));
        $cb = Codebase::load($files, \FastPhp\Analysis\ComposerMap::forProject($this->project->root));
        $rows = Ranker::rank($cb, $functions, $wall, $this->project->rel(...));

        // A second run that also times builtins. A candidate whose time is mostly inside preg_*,
        // explode() and friends is already running C, and porting it gains less. Time spent
        // waiting on databases, files and the network can't be ported at all.
        array_map('unlink', glob("$dir/prof-*.json") ?: []);
        $warm = [];
        $w->once($this->php, $this->project, [$runtime], true, [], ['FASTPHP_PROF_DIR' => $dir, 'FASTPHP_PROF_INTERNAL' => '1'], function () use ($dir, &$warm) {
            $warm = $this->snapshot($dir);
        });
        [$withBuiltins, $wall2] = $this->readProfiles($dir, $warm);
        $own = [];
        $cum = [];
        $io = 0.0;
        $autoload = 0.0;
        foreach ($withBuiltins as $fn) {
            $key = strtolower(($fn['class'] !== null ? $fn['class'] . '::' : '') . $fn['name']);
            if ($fn['internal']) {
                if (self::waits($fn['class'], $fn['name'])) {
                    $io += $fn['cum'];
                }
                continue;
            }
            $own[$key] = ($own[$key] ?? 0) + $fn['own'];
            if ($fn['cum'] > 0) {
                $cum[$key] = $fn['cum'];
            }
            if ($key === 'composer\\autoload\\classloader::loadclass') {
                $autoload += $fn['cum'];
            }
        }
        foreach (array_keys(array_filter($rows, static fn ($r) => $r['verdict'] === 'port')) as $i) {
            $key = strtolower($rows[$i]['name']);
            $total = $cum[$key] ?? 0;
            if ($total <= 0) {
                continue;
            }
            $php = $own[$key] ?? 0;
            foreach ($rows[$i]['helpers'] as $h) {
                $php += $own[strtolower($h)] ?? 0;
            }
            $rows[$i]['builtinShare'] = max(0.0, min(1.0, 1 - $php / $total));
        }
        $ioShare = $wall2 > 0 ? min(1.0, $io / $wall2) : 0.0;
        $autoloadShare = $wall2 > 0 ? min(1.0, $autoload / $wall2) : 0.0;

        $this->project->state['scan'] = [
            'php' => $this->php->version(),
            'jitOff' => $best['off'],
            'jitOn' => $best['on'],
            'profiled' => $wall,
            'io' => $ioShare,
            'autoload' => $autoloadShare,
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

        if ($ioShare >= 0.2) {
            $this->io->note(sprintf('%s of the time is spent waiting on the database, files or the network (PDO, mysqli, curl, streams). No port makes that faster: look at queries and caching first.', self::pct($ioShare)));
        }
        if ($autoloadShare >= 0.1) {
            $this->io->note(sprintf('%s of the time is Composer autoloading classes. That is bootstrap cost, not a port: `composer dump-autoload --classmap-authoritative` and opcache.preload cut it.', self::pct($autoloadShare)));
        }
        $picks = array_values(array_filter($rows, static fn ($r) => $r['verdict'] === 'port'));
        if (!$picks) {
            $this->io->warning('Nothing qualifies: every hot function is blocked or under ' . self::pct(Ranker::WORTH_IT) . ' of the run.');
            return self::SUCCESS;
        }
        $top = $picks[0];
        $this->io->success(sprintf('Port %s first: %s of the run%s.', $top['name'], self::pct($top['share']), $top['helpers'] ? ', including ' . implode(', ', $top['helpers']) : ''));
        if (($top['builtinShare'] ?? 0) >= 0.5) {
            $this->io->note(sprintf('%s of its time is inside PHP builtins (%s), which are C already. The port wins on the PHP loops around them; expect a smaller speedup than the share suggests.', self::pct($top['builtinShare']), 'preg_*, explode(), number_format() and the like'));
        }
        $this->io->writeln("Next: <info>vendor/bin/fast-php port {$top['id']}</info>");
        return self::SUCCESS;
    }

    /**
     * Merges the profiles of every PHP process, minus what $before (a snapshot of the same
     * processes, see snapshot()) had already counted: a web scan leaves the warm-up passes out.
     *
     * @param array<string, array<string, mixed>> $before
     * @return array{0: list<array<string, mixed>>, 1: float, 2: int}
     */
    private function readProfiles(string $dir, array $before = []): array
    {
        $by = [];
        $wall = 0.0;
        $requests = 0;
        foreach ($this->snapshot($dir) as $file => $p) {
            $b = $before[$file] ?? ['wall' => 0.0, 'requests' => 0, 'functions' => []];
            $wall += $p['wall'] - $b['wall'];
            $requests += ($p['requests'] ?? 1) - ($b['requests'] ?? 0);
            $old = [];
            foreach ($b['functions'] as $f) {
                $old[implode("\0", [$f['class'], $f['name'], $f['file'], $f['line']])] = $f;
            }
            foreach ($p['functions'] as $f) {
                $k = implode("\0", [$f['class'], $f['name'], $f['file'], $f['line']]);
                foreach (['calls', 'own', 'cum'] as $n) {
                    $f[$n] -= $old[$k][$n] ?? 0;
                }
                if ($f['calls'] <= 0) {
                    continue;
                }
                if (isset($by[$k])) {
                    foreach (['calls', 'own', 'cum'] as $n) {
                        $by[$k][$n] += $f[$n];
                    }
                } else {
                    $by[$k] = $f;
                }
            }
        }
        return [array_values($by), $wall, $requests];
    }

    /** Every process's profile as it stands. @return array<string, array<string, mixed>> */
    private function snapshot(string $dir): array
    {
        $out = [];
        foreach (glob("$dir/prof-*.json") ?: [] as $file) {
            $p = json_decode((string) file_get_contents($file), true);
            if (is_array($p)) {
                $out[basename($file)] = $p;
            }
        }
        return $out;
    }

    /** How many processes wrote a profile (php -S workers that served a request). */
    private function workers(string $dir): int
    {
        $n = 0;
        foreach (glob("$dir/prof-*.json") ?: [] as $file) {
            $p = json_decode((string) file_get_contents($file), true);
            $n += is_array($p) && ($p['requests'] ?? 0) > 0 ? 1 : 0;
        }
        return max(1, $n);
    }

    /** Builtins that wait on something outside PHP: databases, files, the network, sleep. */
    public static function waits(?string $class, string $name): bool
    {
        if ($class !== null) {
            return (bool) preg_match('/^(PDO|PDOStatement|mysqli(_stmt|_result)?|SQLite3(Stmt|Result)?|Redis(Cluster)?|Memcached?|SoapClient|MongoDB\\\\Driver\\\\.*|SplFileObject|AMQP\\w+)$/i', $class);
        }
        $name = strtolower($name);
        return (bool) preg_match('/^(mysqli_|pg_|sqlite_|oci_|odbc_|socket_|stream_(select|socket_|get_contents|copy)|curl_(exec|multi_exec|multi_select)|f(open|read|write|gets|getcsv|puts|lush|sockopen|passthru)$|file(_get_contents|_put_contents)?$|readfile$|u?sleep$|time_nanosleep$|mail$|dns_|gethostbyname|proc_close$|shell_exec$|exec$|system$|passthru$)/', $name);
    }
}
