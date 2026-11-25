<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Analysis\Codebase;
use FastPhp\Patch\Patcher;
use FastPhp\Php\Process;
use FastPhp\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand('verify', 'Build a port and replay every recorded call against it, inside PHP, compared with ===')]
final class VerifyCommand extends Base
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Port id')
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Seconds for the per-call benchmark', '2')
            ->addOption('loose-messages', null, InputOption::VALUE_NONE, 'Accept exceptions of the right class with a different message');
    }

    protected function handle(InputInterface $in): int
    {
        $port = $this->project->port($in->getArgument('id'));
        $id = $port['id'];
        $this->io->section("Building {$port['crate']}");
        $so = $this->cargo->package($port['crate'], ['standalone']);

        $file = $this->project->abs($port['file']);
        $original = null;
        $cb = Codebase::load([$file]);
        if ($cb->declarationsOnly($file)) {
            // The original without any fast-php prologue, so it runs as PHP even with the port loaded.
            $copy = $this->project->path('tmp', "original-$id.php");
            @mkdir(dirname($copy), 0777, true);
            file_put_contents($copy, Patcher::stripAll((string) file_get_contents($file)));
            $original = ['file' => $copy, 'class' => $port['class'], 'function' => $port['function'],
                'instance' => $port['instance'] ?? false, 'props' => $port['props'] ?? [], 'nargs' => $port['nargs'] ?? count($port['params']),
                'autoload' => is_file($this->project->root . '/vendor/autoload.php') ? $this->project->root . '/vendor/autoload.php' : null];
        }
        $cfg = $this->project->path('tmp', "verify-$id.json");
        file_put_contents($cfg, json_encode([
            'cases' => $this->project->path('ports', $id, 'cases.jsonl'),
            'native' => $port['native'],
            'fallback' => $port['fallback'],
            'original' => $original,
            'budget' => (float) $in->getOption('budget'),
            'looseMessages' => (bool) $in->getOption('loose-messages'),
        ], JSON_UNESCAPED_SLASHES));

        $this->io->section('Replaying ' . ($port['recorded'] + $port['derived']) . ' calls');
        // The replay holds every case and both answers at once: no memory limit.
        $p = Process::run($this->php->command(Project::toolRoot() . '/res/replay.php', [$cfg], [$so], true, ['-d', 'memory_limit=-1']));
        $lines = array_filter(explode("\n", trim($p->stdout)));
        $r = json_decode((string) end($lines), true);
        if (!is_array($r) || isset($r['error'])) {
            throw new \RuntimeException('The replay failed: ' . ($r['error'] ?? trim($p->stderr . "\n" . $p->stdout)));
        }

        $this->io->writeln(sprintf('  %d of %d match%s', $r['passed'], $r['total'] - $r['fellBack'], $r['fellBack'] ? sprintf(' (%d more fell back to PHP)', $r['fellBack']) : ''));
        foreach ($r['failures'] as $fail) {
            $this->io->writeln(sprintf('  <error>case #%d%s</error> %s(%s): %s', $fail['case'], $fail['derived'] ? ' (derived)' : '', $port['name'], implode(', ', $fail['args']), $fail['why']));
        }
        if ($r['failed'] > count($r['failures'])) {
            $this->io->writeln(sprintf('  … and %d more', $r['failed'] - count($r['failures'])));
        }
        foreach ($r['warnings'] as $w) {
            $this->io->writeln("  <comment>note</comment> $w");
        }

        $speedup = null;
        if ($r['bench'] !== null) {
            $b = $r['bench'];
            $speedup = $b['php'] / max($b['native'], 1e-12);
            $this->io->writeln(sprintf('  per call: PHP %s, native %s (%.1f×)%s', self::ms($b['php']), self::ms($b['native']), $speedup, $b['jit'] ? ', JIT on' : ''));
        } elseif ($r['benchSkipped'] ?? null) {
            $this->io->writeln("  per-call benchmark skipped: {$r['benchSkipped']}. `fast-php bench` times the whole app.");
        } else {
            $this->io->writeln("  {$port['file']} does more than declare functions, so the per-call benchmark is skipped; `fast-php bench` times the whole app.");
        }

        $ok = $r['failed'] === 0 && $r['passed'] > 0 && ($speedup === null || $speedup > 1.0);
        $this->project->state['ports'][$id]['status'] = $ok ? 'verified' : 'failed';
        $this->project->state['ports'][$id]['verify'] = [
            'total' => $r['total'], 'passed' => $r['passed'], 'fellBack' => $r['fellBack'], 'failed' => $r['failed'],
            'php' => $r['bench']['php'] ?? null, 'native' => $r['bench']['native'] ?? null,
        ];
        $this->project->save();

        if ($ok) {
            $this->io->success("$id is verified.");
            $this->io->writeln("Next: <info>vendor/bin/fast-php enable $id</info>");
            return self::SUCCESS;
        }
        $this->io->error(match (true) {
            $r['failed'] > 0 => "$id gives different results on {$r['failed']} calls.",
            $r['passed'] === 0 => "$id declined every call, so PHP handled them all. Is port.rs written yet?",
            default => "$id matches but isn't faster than PHP.",
        });
        return self::FAILURE;
    }
}
