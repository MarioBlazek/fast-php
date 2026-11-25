<?php
declare(strict_types=1);

namespace FastPhp\Workload;

use FastPhp\Php\Php;
use FastPhp\Project;

/**
 * What fast-php runs to profile, record and benchmark: a CLI script, or web requests replayed
 * against php -S.
 */
abstract class Workload
{
    /** @param array<string, mixed> $state */
    public static function fromState(array $state): self
    {
        return ($state['type'] ?? 'cli') === 'web' ? WebWorkload::fromState($state) : CliWorkload::fromState($state);
    }

    /** @return array<string, mixed> */
    abstract public function toState(): array;

    abstract public function describe(): string;

    public function isWeb(): bool
    {
        return $this instanceof WebWorkload;
    }

    /**
     * Runs it once. Throws when it fails (a non-zero exit, a 5xx response). With $warmed, a web
     * workload first warms the server up, then calls $warmed, then replays the timed pass; a
     * CLI workload has nothing to warm and ignores it.
     *
     * @param list<string>          $extensions absolute paths of extensions to load
     * @param list<string>          $extra      more php options
     * @param array<string, string> $env
     */
    abstract public function once(Php $php, Project $project, array $extensions, bool $jit, array $extra = [], array $env = [], ?\Closure $warmed = null): Run;

    /**
     * The fastest of $runs runs. A web workload warms opcache and the JIT up first.
     *
     * @param list<string> $extensions
     */
    abstract public function best(Php $php, Project $project, array $extensions, bool $jit, int $runs): Run;
}
