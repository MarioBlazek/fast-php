<?php
declare(strict_types=1);

namespace FastPhp;

/**
 * The app being sped up and fast-php's working directory inside it (.fast-php/).
 *
 * state.json holds the workload, the last scan and every port's status. Patches that a killed
 * run may have left in the source are listed there too, and restored on the next command.
 */
final class Project
{
    public const DIR = '.fast-php';

    /** @var array<string, mixed> */
    public array $state;

    public function __construct(public readonly string $root)
    {
        $file = $this->path('state.json');
        $this->state = is_file($file) ? json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    public static function here(): self
    {
        return new self((string) getcwd());
    }

    /** This package's own directory (bin/, res/, rust/). */
    public static function toolRoot(): string
    {
        return dirname(__DIR__);
    }

    public function path(string ...$parts): string
    {
        return implode('/', [$this->root, self::DIR, ...$parts]);
    }

    public function save(): void
    {
        if (!is_dir($this->path())) {
            mkdir($this->path(), 0777, true);
            file_put_contents($this->path('.gitignore'), "/target/\n/tmp/\n");
        }
        $tmp = $this->path('state.json.tmp');
        file_put_contents($tmp, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        rename($tmp, $this->path('state.json'));
    }

    /** A path relative to the project root, for display and for state.json. */
    public function rel(string $path): string
    {
        $root = rtrim($this->root, '/') . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    public function abs(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->root . '/' . $path;
    }

    /** @return array<string, mixed> */
    public function port(string $id): array
    {
        return $this->state['ports'][$id] ?? throw new \RuntimeException("No port named $id. Run `fast-php port $id` first.");
    }

    /** @return array<string, mixed> */
    public function hotspot(string $id): array
    {
        foreach ($this->state['scan']['functions'] ?? [] as $f) {
            if (strcasecmp($f['id'], $id) === 0) {
                return $f;
            }
        }
        throw new \RuntimeException("No function $id in the last scan. Run `fast-php scan -- php your-script.php` first.");
    }

    public function workload(): Workload\Workload
    {
        return Workload\Workload::fromState($this->state['workload'] ?? throw new \RuntimeException('No workload yet. Run `fast-php scan -- php your-script.php` (or `fast-php scan --web requests.http`) first.'));
    }
}
