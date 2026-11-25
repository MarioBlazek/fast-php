<?php
declare(strict_types=1);

namespace FastPhp\Agent;

use FastPhp\Php\Process;

/**
 * The coding agent that writes port.rs: Claude Code, Codex, or any shell command.
 *
 * Each runs non-interactively in the project root, allowed to edit files and to run
 * `fast-php verify` on its port, nothing else. A shell command gets the same information in
 * environment variables: FASTPHP_PROMPT, FASTPHP_ID, FASTPHP_TASK, FASTPHP_PORT,
 * FASTPHP_FEEDBACK (empty on the first attempt), FASTPHP_ATTEMPT and FASTPHP_VERIFY.
 */
final class Agent
{
    private function __construct(
        public readonly string $kind,
        public readonly string $command,
        public readonly ?string $model,
    ) {
    }

    public static function named(string $spec, ?string $model = null): self
    {
        return match ($spec) {
            'claude' => new self('claude', 'claude', $model),
            'codex' => new self('codex', 'codex', $model),
            default => new self('command', $spec, $model),
        };
    }

    public function label(): string
    {
        return $this->kind === 'command' ? "`{$this->command}`" : ($this->kind === 'claude' ? 'Claude Code' : 'Codex') . ($this->model !== null ? " ({$this->model})" : '');
    }

    /** The program the agent needs, or null for a shell command (sh is always there). */
    public function binary(): ?string
    {
        return $this->kind === 'command' ? null : $this->command;
    }

    /**
     * @param array<string, string> $vars FASTPHP_* for a shell command
     * @return list<string>
     */
    public function argv(string $prompt, string $root, string $verify, array $vars = []): array
    {
        $model = $this->model !== null ? ['--model', $this->model] : [];
        return match ($this->kind) {
            // Edits are accepted; the only command it may run is this port's verify.
            'claude' => ['claude', '-p', $prompt, '--permission-mode', 'acceptEdits', '--output-format', 'text',
                '--allowedTools', "Read,Edit,Write,Glob,Grep,Bash($verify:*)", ...$model],
            // workspace-write: it may write inside the project and run commands there, offline.
            'codex' => ['codex', 'exec', '-C', $root, '--skip-git-repo-check', '-s', 'workspace-write', ...$model, $prompt],
            default => ['sh', '-c', $this->command],
        };
    }

    /**
     * @param array<string, string> $vars
     */
    public function run(string $prompt, string $root, string $verify, array $vars, string $log, float $timeout): Process
    {
        $p = Process::run($this->argv($prompt, $root, $verify, $vars), $root, $vars + ['FASTPHP_PROMPT' => $prompt], timeout: $timeout);
        file_put_contents($log, '$ ' . Process::quote($this->argv('<prompt>', $root, $verify)) . "\n\n" . $prompt . "\n\n---- stdout\n" . $p->stdout . "\n---- stderr\n" . $p->stderr . "\n---- exit {$p->exit}\n");
        return $p;
    }

    /** What the agent is asked to do. */
    public static function prompt(string $id, string $display, string $dir, string $verify, int $attempt, ?string $feedback): string
    {
        $lines = [
            "Port the PHP function $display to Rust for fast-php, so it can run as a native PHP extension.",
            '',
            "1. Read $dir/TASK.md. It has the PHP original and its helpers, the Rust signature to implement, the PHP semantics the port must match exactly, and real recorded calls.",
            "2. Write the port in $dir/src/port.rs. Change only that file: $dir/src/glue.rs is generated, and the PHP source must stay as it is.",
            "3. Run `$verify`. It builds the crate (compiler errors show up there) and replays every recorded call against the port, compared with PHP's ===, exceptions and messages included. It must report every call matching and the port faster than PHP.",
            '4. Repeat 2 and 3 until verify passes.',
            '',
            'Use fastphp_std for PHP builtins (trim, strtolower, preg_*, round, number_format, sorting...): Rust\'s own versions differ from PHP\'s. Where a call can\'t be matched exactly (PHP would warn, or overflow an int into a float), return Err(PhpError::fallback()): PHP then runs that call itself.',
        ];
        if ($attempt > 1 && $feedback !== null) {
            $lines[] = '';
            $lines[] = "This is attempt $attempt. The previous port failed; $feedback has what went wrong. Start from the port.rs that is there now and fix it.";
        }
        return implode("\n", $lines);
    }
}
