<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Rust\Cargo;
use FastPhp\Web\Client;
use FastPhp\Workload\CliWorkload;
use FastPhp\Workload\Run;
use FastPhp\Workload\WebWorkload;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand('bench', 'Time the whole workload with the enabled ports against plain PHP, and check the output is identical')]
final class BenchCommand extends Base
{
    protected function configure(): void
    {
        $this->addOption('runs', null, InputOption::VALUE_REQUIRED, 'Runs (web: passes over the request file) of each, interleaved', '5')
            ->addOption('no-jit', null, InputOption::VALUE_NONE, 'Compare with the JIT off')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Web: replay the requests against this server (your FPM) instead of php -S')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'With --url: save this run under a name ("before", "after") and compare it with the last other one')
            ->setHelp(<<<'TXT'
                A CLI workload runs the script with and without the ports, interleaved, and compares
                what it prints.

                A web workload starts two php -S servers, one with the extension and one without,
                replays the request file against each in turn, and compares p50, p95 and requests
                per second. Every response must have the same status and body.

                Against your own server (PHP-FPM behind nginx, say), fast-php can't switch the
                extension itself. Run it once before and once after `fast-php install` and an FPM
                restart:

                  vendor/bin/fast-php bench --url http://localhost:8080 --label before
                  vendor/bin/fast-php bench --url http://localhost:8080 --label after
                TXT);
    }

    protected function handle(InputInterface $in): int
    {
        $w = $this->project->workload();
        $runs = max(1, (int) $in->getOption('runs'));
        if ($in->getOption('url') !== null) {
            if (!$w instanceof WebWorkload) {
                throw new \RuntimeException('--url needs a web workload: run `fast-php scan --web requests.http` first.');
            }
            return $this->url($w, $in->getOption('url'), (string) ($in->getOption('label') ?? 'run'), $runs);
        }
        $so = $this->project->path('build', Cargo::libName('fastphp_app'));
        if (!is_file($so)) {
            throw new \RuntimeException('No port is enabled. Run `fast-php enable <id>` first.');
        }
        $jit = !$in->getOption('no-jit');
        $enabled = array_keys(array_filter($this->project->state['ports'], static fn ($p) => $p['enabled']));
        $this->io->section('Benchmarking ' . implode(', ', $enabled) . ' (JIT ' . ($jit ? 'on' : 'off') . ')');
        if ($w instanceof WebWorkload) {
            $w->requests($this->project);
        }
        $this->io->writeln('  ' . $w->describe());

        [$php, $native] = $w instanceof WebWorkload ? $this->web($w, $so, $jit, $runs) : $this->cli($w, $so, $jit, $runs);

        $diff = $php[0]->firstDifference($native[0]);
        foreach (['PHP' => $php, 'ports' => $native] as $side => $all) {
            foreach ($all as $r) {
                if (($i = $all[0]->firstDifference($r)) !== null) {
                    $this->io->warning("The $side runs don't all give the same output ({$r->labels[$i]} differs between runs). The workload isn't deterministic, so outputs can't be compared.");
                    break;
                }
            }
        }
        $total = static fn (array $all) => self::median(array_map(static fn (Run $r) => $r->seconds, $all));
        $med = ['php' => $total($php), 'native' => $total($native)];
        $speedup = $med['php'] / $med['native'];
        $state = ['jit' => $jit, 'php' => $med['php'], 'native' => $med['native'], 'identical' => $diff === null, 'ports' => $enabled];

        if ($w instanceof WebWorkload) {
            $lat = static fn (array $all) => array_merge(...array_map(static fn (Run $r) => $r->latencies, $all));
            $n = count($php[0]->latencies);
            $rows = [];
            foreach (['PHP' => $php, 'with ports' => $native] as $side => $all) {
                $l = $lat($all);
                $rows[] = [$side, self::ms(self::quantile($l, 0.5)), self::ms(self::quantile($l, 0.95)), self::ms(self::quantile($l, 0.99)), sprintf('%.0f', $n / $total($all))];
                $state[$side === 'PHP' ? 'phpLatency' : 'nativeLatency'] = ['p50' => self::quantile($l, 0.5), 'p95' => self::quantile($l, 0.95), 'rps' => $n / $total($all)];
            }
            $this->io->table(['', 'p50', 'p95', 'p99', 'requests/s'], $rows);
            $this->io->writeln(sprintf('  %d requests per pass, %d passes each, %d in flight', $n, $runs, $w->workers));
        } else {
            $this->io->table(['', 'median', 'best', 'runs'], [
                ['PHP', self::ms($med['php']), self::ms(min(array_map(static fn (Run $r) => $r->seconds, $php))), $runs],
                ['with ports', self::ms($med['native']), self::ms(min(array_map(static fn (Run $r) => $r->seconds, $native))), $runs],
            ]);
        }
        $this->project->state['bench'] = $state;
        $this->project->save();
        if ($diff !== null) {
            $this->io->error("The output with the ports differs from plain PHP ({$php[0]->labels[$diff]}). Disable them (`fast-php disable --all`) and check the recorded cases cover this input.");
            return self::FAILURE;
        }
        if ($w instanceof WebWorkload) {
            $p95 = $state['phpLatency']['p95'] / $state['nativeLatency']['p95'];
            $this->io->success(sprintf('%.1f× faster over the whole replay (%s → %s), p95 %.1f× lower; every response identical.', $speedup, self::ms($med['php']), self::ms($med['native']), $p95));
            $this->io->writeln('For PHP-FPM: <info>vendor/bin/fast-php install</info>');
        } else {
            $this->io->success(sprintf('%.1f× faster end to end (%s → %s), identical output.', $speedup, self::ms($med['php']), self::ms($med['native'])));
        }
        return self::SUCCESS;
    }

    /** @return array{0: list<Run>, 1: list<Run>} */
    private function cli(CliWorkload $w, string $so, bool $jit, int $runs): array
    {
        $this->io->writeln('  ' . \FastPhp\Php\Process::quote($w->command($this->php, $this->project, [$so], $jit)));
        $out = [[], []];
        for ($i = 0; $i < $runs; $i++) {
            $out[0][] = $w->once($this->php, $this->project, [$so], $jit, [], ['FAST_PHP_DISABLE' => '1']);
            $out[1][] = $w->once($this->php, $this->project, [$so], $jit, [], ['FAST_PHP_DISABLE' => '0']);
        }
        return $out;
    }

    /**
     * Two servers side by side, each warmed up; then passes alternate between them,
     * so a slow moment of the machine hits both alike.
     *
     * @return array{0: list<Run>, 1: list<Run>}
     */
    private function web(WebWorkload $w, string $so, bool $jit, int $runs): array
    {
        $php = $w->start($this->php, $this->project, [$so], $jit, [], ['FAST_PHP_DISABLE' => '1'], 'bench-php');
        $native = $w->start($this->php, $this->project, [$so], $jit, [], ['FAST_PHP_DISABLE' => '0'], 'bench-ports');
        try {
            $w->warm($this->project, $php->client(), $php);
            $w->warm($this->project, $native->client(), $native);
            $out = [[], []];
            for ($i = 0; $i < $runs; $i++) {
                $out[0][] = $w->pass($this->project, $php->client(), $php);
                $out[1][] = $w->pass($this->project, $native->client(), $native);
            }
            return $out;
        } finally {
            $php->stop();
            $native->stop();
        }
    }

    /** Replays against a server fast-php doesn't run, and compares with an earlier labelled run. */
    private function url(WebWorkload $w, string $url, string $label, int $runs): int
    {
        $client = Client::forUrl($url);
        $this->io->section("Replaying {$w->requestFile} against {$client->address()} ($label)");
        $w->warm($this->project, $client, null, true);
        $all = [];
        for ($i = 0; $i < $runs; $i++) {
            $all[] = $w->pass($this->project, $client, null, true);
        }
        $lat = array_merge(...array_map(static fn (Run $r) => $r->latencies, $all));
        $n = count($all[0]->latencies);
        $result = [
            'at' => date('c'),
            'p50' => self::quantile($lat, 0.5),
            'p95' => self::quantile($lat, 0.95),
            'rps' => $n / self::median(array_map(static fn (Run $r) => $r->seconds, $all)),
            'responses' => array_map('md5', $all[0]->outputs),
            'labels' => $all[0]->labels,
        ];
        $this->io->writeln(sprintf('  p50 %s, p95 %s, %.0f requests/s (%d requests, %d passes, %d in flight)', self::ms($result['p50']), self::ms($result['p95']), $result['rps'], $n, $runs, $w->workers));
        $errors = count(array_filter($all[0]->outputs, static fn (string $o) => (int) $o >= 500));
        if ($errors) {
            $this->io->warning("$errors responses were server errors (5xx).");
        }
        $saved = $this->project->state['urlBench'][$url] ?? [];
        unset($saved[$label]);
        $other = $saved ? array_key_last($saved) : null;
        $this->project->state['urlBench'][$url] = [...$saved, $label => $result];
        $this->project->save();
        if ($other === null) {
            $this->io->writeln("Saved as \"$label\". Enable the ports in that server (`fast-php install`, then restart it) and run this again with another --label to compare.");
            return self::SUCCESS;
        }
        $o = $saved[$other];
        $this->io->table(['', 'p50', 'p95', 'requests/s'], [
            [$other, self::ms($o['p50']), self::ms($o['p95']), sprintf('%.0f', $o['rps'])],
            [$label, self::ms($result['p50']), self::ms($result['p95']), sprintf('%.0f', $result['rps'])],
        ]);
        $differ = [];
        foreach ($result['responses'] as $i => $hash) {
            if (($o['responses'][$i] ?? null) !== $hash) {
                $differ[] = $result['labels'][$i];
            }
        }
        $p95 = $o['p95'] / $result['p95'];
        $this->io->writeln(sprintf('  against "%s": p95 %.1f× %s, %.2f× the requests per second', $other, $p95 >= 1 ? $p95 : 1 / $p95, $p95 >= 1 ? 'lower' : 'higher', $result['rps'] / $o['rps']));
        if ($differ) {
            $this->io->warning(count($differ) . ' responses differ from "' . $other . '", the first: ' . $differ[0] . '. If the app prints times or tokens, that is expected; otherwise disable the ports.');
            return self::FAILURE;
        }
        $this->io->success("Every response is identical to \"$other\".");
        return self::SUCCESS;
    }

    /** @param list<float> $xs */
    private static function median(array $xs): float
    {
        sort($xs);
        return $xs[intdiv(count($xs), 2)];
    }
}
