<?php
declare(strict_types=1);

namespace FastPhp\Analysis;

/**
 * Finds the file of a class the way the project's Composer autoloader would (classmap, then
 * PSR-4), from vendor/composer/autoload_*.php. Lets the analysis follow a call into a helper
 * class that the profiled run never loaded.
 */
final class ComposerMap
{
    /** @param array<string, string> $classmap @param array<string, list<string>> $psr4 */
    private function __construct(private readonly array $classmap, private readonly array $psr4)
    {
    }

    public static function forProject(string $root): self
    {
        $dir = $root . '/vendor/composer';
        $load = static fn (string $file) => is_file($file) ? (static fn () => require $file)() : [];
        $psr4 = $load("$dir/autoload_psr4.php");
        uksort($psr4, static fn ($a, $b) => strlen($b) <=> strlen($a)); // longest prefix first
        return new self($load("$dir/autoload_classmap.php"), $psr4);
    }

    public function find(string $class): ?string
    {
        $class = ltrim($class, '\\');
        if (isset($this->classmap[$class]) && is_file($this->classmap[$class])) {
            return $this->classmap[$class];
        }
        foreach ($this->psr4 as $prefix => $dirs) {
            if (str_starts_with($class, $prefix)) {
                $rel = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                foreach ($dirs as $d) {
                    if (is_file("$d/$rel")) {
                        return "$d/$rel";
                    }
                }
            }
        }
        return null;
    }
}
