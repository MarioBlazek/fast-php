<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Agent\Agent;
use FastPhp\Agent\Guard;
use FastPhp\Php\Process;
use FastPhp\Project;
use FastPhp\Rust\Cargo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('go', 'Scan, then have a coding agent port the hottest functions, verify (retrying with the failures), enable, bench and report')]
final class GoCommand extends Base
{
    protected function configure(): void
    {
        $this->addArgument('workload', InputArgument::IS_ARRAY, 'As for scan, after --: php app.php [args], or with --web: php -S localhost:8000 -t public')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'claude, codex, or a shell command (it gets FASTPHP_* variables)', 'claude')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model for claude or codex')
            ->addOption('id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Port these functions (default: the top candidates of the scan)')
            ->addOption('ports', null, InputOption::VALUE_REQUIRED, 'How many of the top candidates to port', '1')
            ->addOption('retries', null, InputOption::VALUE_REQUIRED, 'Further attempts after a port fails verify, each given the failures', '3')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds an agent may take per attempt', '1800')
            ->addOption('web', null, InputOption::VALUE_REQUIRED, 'Scan web requests from this file (see scan --help)')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, 'php -S workers (--web)', '1')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Replays of the request file per pass (--web)', '1')
            ->addOption('rescan', null, InputOption::VALUE_NONE, 'Scan again even though there is a scan of this workload')
            ->addOption('no-enable', null, InputOption::VALUE_NONE, 'Stop at verified ports: don\'t patch the source')
            ->addOption('no-bench', null, InputOption::VALUE_NONE, 'Skip the whole-workload benchmark')
            ->setHelp(<<<'TXT'
                The whole pipeline, with a coding agent writing the Rust:

                  vendor/bin/fast-php go -- php bin/import.php data.csv
                  vendor/bin/fast-php go --agent codex --web requests.http -- php -S localhost:8000 -t public

                For each candidate: port (record calls, write TASK.md), then the agent writes
                src/port.rs and may run `fast-php verify` itself. fast-php then verifies on its own;
                if a call differs, the build fails, or the port is slower than PHP, the output goes
                to the agent as FEEDBACK.md for the next attempt. The agent may only change port.rs:
                anything else it changes in the crate or in the scanned PHP files is put back.

                A shell command as the agent runs in the project root with FASTPHP_PROMPT,
                FASTPHP_ID, FASTPHP_TASK, FASTPHP_PORT, FASTPHP_FEEDBACK, FASTPHP_ATTEMPT and
                FASTPHP_VERIFY set, for any other agent CLI.
                TXT);
    }

    protected function handle(InputInterface $in): int
    {
        $agent = Agent::named((string) $in->getOption('agent'), $in->getOption('model'));
        if (($bin = $agent->binary()) !== null && trim(Process::run(['sh', '-c', 'command -v "$0"', $bin])->stdout) === '') {
            throw new \RuntimeException("$bin isn't on PATH. Install it, or choose another agent: --agent claude, --agent codex, or --agent '<your command>'.");
        }

        $tokens = $in->getArgument('workload');
        if ($tokens || $in->getOption('web') !== null || $in->getOption('rescan') || !isset($this->project->state['scan'])) {
            $args = ['workload' => $tokens];
            if ($in->getOption('web') !== null) {
                $args += ['--web' => $in->getOption('web'), '--workers' => $in->getOption('workers'), '--repeat' => $in->getOption('repeat')];
            }
            if ($this->sub('scan', $args) !== self::SUCCESS) {
                return self::FAILURE;
            }
        } else {
            $this->io->writeln('Using the last scan of ' . $this->project->workload()->describe() . ' (--rescan to scan again).');
        }
        $project = Project::here();

        $ids = $in->getOption('id');
        if (!$ids) {
            $done = static fn (array $r) => in_array($project->state['ports'][$r['id']]['status'] ?? null, ['verified'], true) || ($project->state['ports'][$r['id']]['enabled'] ?? false);
            $picks = array_filter($project->state['scan']['functions'], static fn (array $r) => $r['verdict'] === 'port' && !$done($r));
            $ids = array_slice(array_column($picks, 'id'), 0, max(1, (int) $in->getOption('ports')));
        }
        if (!$ids) {
            $this->io->warning('Nothing left to port: no candidate in the scan, or every one is verified already.');
            return self::SUCCESS;
        }

        Cargo::$captureErrors = true;
        $results = [];
        foreach ($ids as $id) {
            $results[$id] = $this->one($id, $agent, $in);
        }

        $project = Project::here();
        $enabled = array_filter($project->state['ports'] ?? [], static fn (array $p) => $p['enabled']);
        if ($enabled && !$in->getOption('no-bench')) {
            $this->sub('bench', ['--runs' => '3']);
        }
        $this->sub('report', []);

        $this->io->section('Summary');
        $project = Project::here();
        $rows = [];
        foreach ($results as $id => [$ok, $attempts, $why]) {
            $v = $project->state['ports'][$id]['verify'] ?? null;
            $rows[] = [$id, $ok ? '<info>verified</info>' . (($project->state['ports'][$id]['enabled'] ?? false) ? ', enabled' : '') : "<error>$why</error>", $attempts,
                $v && $v['php'] && $v['native'] ? sprintf('%.1f×', $v['php'] / $v['native']) : '-'];
        }
        $this->io->table(['port', 'outcome', 'attempts', 'per call'], $rows);
        $failed = count(array_filter($results, static fn ($r) => !$r[0]));
        if ($failed) {
            $this->io->error($failed === count($results) ? 'No port passed verify.' : "$failed of " . count($results) . ' ports failed verify.');
            $this->io->writeln('Each attempt\'s log is in .fast-php/ports/<id>/agent-<n>.log, the last failures in FEEDBACK.md.');
            return self::FAILURE;
        }
        return self::SUCCESS;
    }

    /** Ports one function. @return array{0: bool, 1: int, 2: string} verified, attempts, why not */
    private function one(string $id, Agent $agent, InputInterface $in): array
    {
        $this->io->title($id);
        if ($this->sub('port', ['id' => $id]) !== self::SUCCESS) {
            return [false, 0, 'recording failed'];
        }
        $project = Project::here();
        $port = $project->port($id);
        $dir = $project->path('ports', $id);
        $rel = $project->rel($dir);

        // Build the stub first: dependencies are fetched and compiled before the agent starts
        // (Codex's sandbox has no network), and a broken scaffold isn't blamed on the agent.
        $this->io->writeln('Building the crate before the agent starts…');
        (new Cargo($project))->package($port['crate'], ['standalone']);

        $phpFiles = array_values(array_unique(array_map(static fn (array $r) => $project->abs($r['file']), $project->state['scan']['functions'])));
        $guard = new Guard([
            ...Guard::tree($dir, static fn (string $p) => str_ends_with($p, '/src/port.rs') || str_ends_with($p, '.log') || str_ends_with($p, '/FEEDBACK.md')),
            ...array_filter($phpFiles, 'is_file'),
        ]);
        $verify = Process::quote([PHP_BINARY, Project::toolRoot() . '/bin/fast-php', 'verify', $id]);
        $feedback = "$dir/FEEDBACK.md";
        @unlink($feedback);
        foreach (glob("$dir/agent-*.log") ?: [] as $old) {
            unlink($old);
        }

        $attempts = 1 + max(0, (int) $in->getOption('retries'));
        $why = 'failed verify';
        for ($n = 1; $n <= $attempts; $n++) {
            $this->io->section("Attempt $n of $attempts: {$agent->label()} writes $rel/src/port.rs");
            $prompt = Agent::prompt($id, $port['name'], $rel, $verify, $n, is_file($feedback) ? "$rel/FEEDBACK.md" : null);
            $log = "$dir/agent-$n.log";
            $p = $agent->run($prompt, $project->root, $verify, [
                'FASTPHP_ID' => $id,
                'FASTPHP_TASK' => "$dir/TASK.md",
                'FASTPHP_PORT' => "$dir/src/port.rs",
                'FASTPHP_FEEDBACK' => is_file($feedback) ? $feedback : '',
                'FASTPHP_ATTEMPT' => (string) $n,
                'FASTPHP_VERIFY' => $verify,
            ], $log, (float) $in->getOption('timeout'));
            $notes = [];
            if ($p->exit === 124) {
                $notes[] = sprintf('The agent ran out of time (%d s) and was stopped.', (int) $in->getOption('timeout'));
            } elseif ($p->exit !== 0) {
                $notes[] = "The agent exited with {$p->exit}.";
            }
            $this->io->writeln(sprintf('  agent finished in %s (log: %s)', self::ms($p->seconds), $project->rel($log)));
            if ($restored = $guard->restore()) {
                $notes[] = 'Only src/port.rs may change. These files were changed and have been put back: ' . implode(', ', array_map($project->rel(...), $restored)) . '.';
            }
            foreach ($notes as $note) {
                $this->io->writeln("  <comment>!</comment> $note");
            }

            $out = new BufferedOutput();
            $ok = $this->sub('verify', ['id' => $id], $out) === self::SUCCESS;
            $text = trim($out->fetch());
            $this->io->writeln('  ' . str_replace("\n", "\n  ", $text));
            if ($ok) {
                @unlink($feedback);
                if (!$in->getOption('no-enable')) {
                    $this->sub('enable', ['id' => $id]);
                }
                return [true, $n, ''];
            }
            $why = str_contains($text, 'cargo build failed') ? 'does not compile' : (str_contains($text, "isn't faster") ? 'slower than PHP' : 'failed verify');
            file_put_contents($feedback, "# Attempt $n failed\n\n" . ($notes ? implode("\n", array_map(static fn ($s) => "- $s", $notes)) . "\n\n" : '')
                . "Output of `$verify`:\n\n```\n$text\n```\n");
        }
        return [false, $attempts, $why];
    }

    /** Runs another fast-php command. @param array<string, mixed> $args */
    private function sub(string $name, array $args, ?OutputInterface $out = null): int
    {
        $input = new ArrayInput(['command' => $name] + $args);
        $input->setInteractive(false);
        return $this->getApplication()->find($name)->run($input, $out ?? $this->output);
    }
}
