<?php
declare(strict_types=1);

namespace FastPhp\Workload;

use FastPhp\Php\Php;
use FastPhp\Php\Process;
use FastPhp\Project;

/** A PHP script run from the command line: `php [options] script.php [args]`. */
final class CliWorkload extends Workload
{
    /**
     * @param list<string> $args
     * @param list<string> $php  php options the user gave (-d ...)
     */
    public function __construct(
        public readonly string $script,
        public readonly array $args = [],
        public readonly array $php = [],
    ) {
    }

    /** From the command after `--`: php [options] script.php [args]. */
    public static function parse(array $tokens, Project $project): self
    {
        [$php, $rest] = self::options($tokens);
        $script = array_shift($rest);
        if ($script === null || !is_file($project->abs($script))) {
            throw new \RuntimeException('Give the script to run after --, for example: fast-php scan -- php app.php');
        }
        return new self($project->rel((string) realpath($project->abs($script))), array_values($rest), $php);
    }

    /**
     * Splits `php -d a=b -n script args` into the php options (without -n: fast-php always
     * adds it) and the rest.
     *
     * @param list<string> $tokens
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function options(array $tokens): array
    {
        if ($tokens && preg_match('/^php[\d.]*$/', basename($tokens[0]))) {
            array_shift($tokens);
        }
        $php = [];
        while ($tokens && str_starts_with($tokens[0], '-') && !in_array($tokens[0], ['-S', '-t'], true)) {
            $opt = array_shift($tokens);
            if ($opt === '-n') {
                continue;
            }
            $php[] = $opt;
            if (in_array($opt, ['-d', '-c'], true) && $tokens) {
                $php[] = array_shift($tokens);
            }
        }
        return [$php, $tokens];
    }

    public static function fromState(array $s): self
    {
        return new self($s['script'], $s['args'] ?? [], $s['php'] ?? []);
    }

    public function toState(): array
    {
        return ['type' => 'cli', 'script' => $this->script, 'args' => $this->args, 'php' => $this->php];
    }

    public function describe(): string
    {
        return trim('php ' . implode(' ', [...$this->php, $this->script, ...$this->args]));
    }

    /** @return list<string> */
    public function command(Php $php, Project $project, array $extensions, bool $jit, array $extra = []): array
    {
        return $php->command($project->abs($this->script), $this->args, $extensions, $jit, [...$this->php, ...$extra]);
    }

    public function once(Php $php, Project $project, array $extensions, bool $jit, array $extra = [], array $env = [], ?\Closure $warmed = null): Run
    {
        $p = Process::run($this->command($php, $project, $extensions, $jit, $extra), $project->root, $env ?: null);
        if ($p->exit !== 0) {
            throw new \RuntimeException("The workload failed (exit {$p->exit}):\n" . trim($p->stderr ?: $p->stdout));
        }
        return new Run($p->seconds, [$p->stdout], [$this->script]);
    }

    public function best(Php $php, Project $project, array $extensions, bool $jit, int $runs): Run
    {
        $best = null;
        for ($i = 0; $i < max(1, $runs); $i++) {
            $r = $this->once($php, $project, $extensions, $jit);
            if ($best === null || $r->seconds < $best->seconds) {
                $best = $r;
            }
        }
        return $best;
    }
}
