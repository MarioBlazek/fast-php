<?php
declare(strict_types=1);

namespace FastPhp\Agent;

/**
 * Keeps an agent to the one file it may change. Takes a copy of the files it must not touch
 * (the generated crate, the recorded cases, the app's PHP source) and puts back any it changed.
 */
final class Guard
{
    /** @var array<string, string|null> path => contents, null when the file didn't exist */
    private array $files = [];

    /** @param list<string> $paths */
    public function __construct(array $paths)
    {
        foreach ($paths as $path) {
            $this->files[$path] = is_file($path) ? (string) file_get_contents($path) : null;
        }
    }

    /** Every file in $dir, recursively, except those $skip says to leave out. @return list<string> */
    public static function tree(string $dir, callable $skip): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !$skip($f->getPathname())) {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    /** Puts back what changed. @return list<string> the files it restored */
    public function restore(): array
    {
        $changed = [];
        foreach ($this->files as $path => $contents) {
            $now = is_file($path) ? (string) file_get_contents($path) : null;
            if ($now === $contents) {
                continue;
            }
            $changed[] = $path;
            if ($contents === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $contents);
            }
        }
        return $changed;
    }
}
