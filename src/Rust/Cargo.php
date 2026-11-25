<?php
declare(strict_types=1);

namespace FastPhp\Rust;

use FastPhp\Php\Process;
use FastPhp\Project;

/**
 * Builds the Rust side: the profiler extension that ships with fast-php, and the project's own
 * workspace in .fast-php/ (one crate per port, plus the app extension that bundles them).
 * Everything lands in .fast-php/target, so rebuilds are incremental.
 */
final class Cargo
{
    public function __construct(private readonly Project $project)
    {
    }

    public static function libName(string $crate): string
    {
        $file = str_replace('-', '_', $crate);
        return PHP_OS_FAMILY === 'Windows' ? "$file.dll" : ('lib' . $file . (PHP_OS_FAMILY === 'Darwin' ? '.dylib' : '.so'));
    }

    /** .fast-php/target, or FAST_PHP_TARGET_DIR to share one build cache between projects (CI). */
    public function target(): string
    {
        return getenv('FAST_PHP_TARGET_DIR') ?: $this->project->path('target');
    }

    /** The observer profiler, built from this package's rust/ workspace. */
    public function runtime(): string
    {
        $this->build(Project::toolRoot() . '/rust', ['-p', 'fastphp-runtime']);
        return $this->artifact('fastphp-runtime');
    }

    /** One package of the project's workspace (.fast-php/). @param list<string> $features */
    public function package(string $crate, array $features = []): string
    {
        $args = ['-p', $crate];
        if ($features) {
            $args[] = '--features';
            $args[] = implode(',', $features);
        }
        $this->build($this->project->path(), $args);
        return $this->artifact($crate);
    }

    private function artifact(string $crate): string
    {
        $file = $this->target() . '/release/' . self::libName($crate);
        if (!is_file($file)) {
            throw new \RuntimeException("cargo finished but $file is missing.");
        }
        return $file;
    }

    /** @param list<string> $args */
    private function build(string $dir, array $args): void
    {
        // cargo reads .cargo/config.toml (macOS link flags) from the directory it runs in.
        $cmd = ['cargo', 'build', '--release', '--quiet', '--target-dir', $this->target(), ...$args];
        $p = Process::run($cmd, $dir, self::env(), passthru: true);
        if ($p->exit !== 0) {
            throw new \RuntimeException('cargo build failed (' . Process::quote($cmd) . " in $dir).");
        }
    }

    /**
     * bindgen (inside ext-php-rs) needs libclang and clang's own headers from the same LLVM.
     * When several LLVMs are installed and nothing is set, pin the newest one.
     *
     * @return array<string, string>
     */
    public static function env(): array
    {
        if (getenv('LIBCLANG_PATH') !== false || PHP_OS_FAMILY !== 'Linux') {
            return [];
        }
        $dirs = glob('/usr/lib/llvm-*', GLOB_ONLYDIR) ?: [];
        usort($dirs, static fn (string $a, string $b) => (int) substr(strrchr($b, '-'), 1) <=> (int) substr(strrchr($a, '-'), 1));
        foreach ($dirs as $d) {
            if (glob("$d/lib/libclang*.so*") && is_file("$d/bin/clang")) {
                return ['LIBCLANG_PATH' => "$d/lib", 'CLANG_PATH' => "$d/bin/clang"];
            }
        }
        return [];
    }
}
