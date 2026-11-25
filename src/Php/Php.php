<?php
declare(strict_types=1);

namespace FastPhp\Php;

/**
 * How fast-php runs PHP: always with -n (no php.ini), so a debugger or profiler loaded from
 * php.ini (Xdebug made the fuzzy example 3.3× slower) can't skew a number. The extensions the
 * app needs that php.ini would have loaded are added back by name; opcache too.
 */
final class Php
{
    /** Loaded by php.ini but never added back: they distort timings. */
    public const SKEWING = ['xdebug', 'pcov', 'blackfire', 'tideways', 'tideways_xhprof', 'xhprof', 'spx', 'excimer', 'memprof', 'ddtrace', 'newrelic', 'opentelemetry'];

    public const JIT = ['-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=64M'];
    public const NO_JIT = ['-d', 'opcache.jit=disable'];

    /** @var list<string>|null */
    private ?array $base = null;

    public function __construct(public readonly string $binary = PHP_BINARY)
    {
    }

    /**
     * php -n plus the extensions php.ini loads, minus the ones that skew timings.
     *
     * @return list<string>
     */
    public function base(): array
    {
        if ($this->base !== null) {
            return $this->base;
        }
        $probe = 'echo json_encode(["ext" => array_map("strtolower", get_loaded_extensions()), "zend" => array_map("strtolower", get_loaded_extensions(true)), "dir" => ini_get("extension_dir")]);';
        $withIni = json_decode(Process::run([$this->binary, '-r', $probe])->stdout, true);
        $bare = json_decode(Process::run([$this->binary, '-n', '-r', $probe])->stdout, true);
        if (!is_array($withIni) || !is_array($bare)) {
            throw new \RuntimeException("Can't run {$this->binary}.");
        }
        $args = [$this->binary, '-n', '-d', 'extension_dir=' . $withIni['dir']];
        foreach (array_diff($withIni['ext'], $bare['ext'], $withIni['zend'], self::SKEWING) as $ext) {
            $args[] = '-d';
            $args[] = 'extension=' . $ext;
        }
        // Zend extensions report a display name ("Zend OPcache"); load them by file name.
        // PHP < 8.5 ships opcache as a shared zend_extension, and the JIT needs it.
        $zend = array_diff($withIni['zend'], $bare['zend'], self::SKEWING);
        if (!in_array('zend opcache', $bare['zend'], true)) {
            $zend[] = 'zend opcache';
        }
        foreach (array_unique($zend) as $ext) {
            $args[] = '-d';
            $args[] = 'zend_extension=' . ($ext === 'zend opcache' ? 'opcache' : $ext);
        }
        return $this->base = $args;
    }

    /** Extensions php.ini loads that fast-php leaves out of every timed run. @return list<string> */
    public function skewing(): array
    {
        $ext = json_decode(Process::run([$this->binary, '-r', 'echo json_encode(array_map("strtolower", array_merge(get_loaded_extensions(), get_loaded_extensions(true))));'])->stdout, true) ?: [];
        return array_values(array_intersect(self::SKEWING, $ext));
    }

    /**
     * @param list<string> $extensions absolute paths of extra extensions to load
     * @param list<string> $extra      more php options (-d ...)
     * @return list<string>
     */
    public function command(string $script, array $args = [], array $extensions = [], bool $jit = true, array $extra = []): array
    {
        $cmd = $this->base();
        foreach ($extensions as $ext) {
            $cmd[] = '-d';
            $cmd[] = 'extension=' . $ext;
        }
        return [...$cmd, ...($jit ? self::JIT : self::NO_JIT), ...$extra, $script, ...$args];
    }

    public function version(): string
    {
        return trim(Process::run([$this->binary, '-n', '-r', 'echo PHP_VERSION, PHP_ZTS ? " ZTS" : " NTS";'])->stdout);
    }
}
