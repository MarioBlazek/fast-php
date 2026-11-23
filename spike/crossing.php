<?php
// Phase 0: what does a call into Rust cost, and when does it pay off?
//   php -d extension=…/libspike_ext.dylib spike/crossing.php …/libspike_ffi.dylib
// Columns: the PHP original, fast-anything's bridge (FFI + JSON, as it generates it), fast-php's extension.
declare(strict_types=1);

require __DIR__ . '/../examples/fuzzy-dedupe-cli/fuzzy.php';

// ---- fast-anything's PHP bridge (src/php/bridge.php), one cdef for all four entry points.
final class Upstream
{
    private static $ffi;
    private static array $params = [];

    public static function load(string $lib): void
    {
        $sig = implode(' ', array_map(fn($f) => "uint8_t *$f(const char *input, size_t len, size_t *out_len);",
            ['fa_noop', 'fa_sum', 'fa_levenshtein', 'fa_findDuplicates']));
        self::$ffi = FFI::cdef($sig . ' void fa_free(uint8_t *ptr, size_t len);', $lib);
    }

    public static function call(string $entry, string $name, array $args)
    {
        $ps = self::$params[$name] ??= (str_contains($name, '::') ? new ReflectionMethod(...explode('::', $name, 2)) : new ReflectionFunction($name))->getParameters();
        for ($i = count($args); $i < count($ps) && $ps[$i]->isDefaultValueAvailable(); $i++) $args[] = $ps[$i]->getDefaultValue();
        $in = json_encode($args, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $len = self::$ffi->new('size_t');
        $out = self::$ffi->$entry($in, strlen($in), FFI::addr($len));
        $n = $len->cdata;
        $text = FFI::string($out, $n);
        self::$ffi->fa_free($out, $n);
        $reply = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (array_key_exists('error', $reply)) throw new RuntimeException($reply['error']);
        return $reply['ok'];
    }
}

function php_noop(): int { return 0; }
function php_sum(array $xs): int { $s = 0; foreach ($xs as $x) $s += $x; return $s; }

/** ns per call: best of several timed rounds, each sized to run about 50 ms. */
function per_call(callable $f, array $args): float
{
    $n = 1;
    while (true) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) $f(...$args);
        $took = hrtime(true) - $t;
        if ($took > 5e7 || $n >= 1 << 24) break;
        $n *= 4;
    }
    $best = $took / $n;
    for ($r = 0; $r < 4; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) $f(...$args);
        $best = min($best, (hrtime(true) - $t) / $n);
    }
    return $best;
}

function fmt(float $ns): string
{
    return match (true) {
        $ns >= 1e9 => sprintf('%.2f s', $ns / 1e9),
        $ns >= 1e6 => sprintf('%.2f ms', $ns / 1e6),
        $ns >= 1e3 => sprintf('%.2f µs', $ns / 1e3),
        default => sprintf('%.0f ns', $ns),
    };
}

Upstream::load($argv[1]);
mt_srand(7);
$names = [];
foreach (range(1, 600) as $_) $names[] = ['Maria', 'Zoë', 'Łukasz', 'José', 'Chen'][mt_rand(0, 4)] . ' ' . ['Rossi', 'Müller', 'Nowak', 'García'][mt_rand(0, 3)] . mt_rand(0, 40);
$ints10 = range(1, 10);
$ints1k = range(1, 1000);

// The last column is fast-php's generated glue: zvals read directly, not through ext-php-rs's generic conversions.
$rows = [
    ['noop()', 'php_noop', [], 'fa_noop', 'php_noop', 'fastphp_spike_noop', 'fastphp_spike_noop'],
    ['sum(10 ints)', 'php_sum', [$ints10], 'fa_sum', 'php_sum', 'fastphp_spike_sum', 'fastphp_glue_sum'],
    ['sum(1,000 ints)', 'php_sum', [$ints1k], 'fa_sum', 'php_sum', 'fastphp_spike_sum', 'fastphp_glue_sum'],
    ['levenshtein(2 names)', 'Fuzzy::levenshtein', ['Giulia Dubois', 'Giulit Dlbois'], 'fa_levenshtein', 'Fuzzy::levenshtein', 'fastphp_spike_levenshtein', 'fastphp_glue_levenshtein'],
    ['findDuplicates(600 names)', 'Fuzzy::findDuplicates', [$names, 2], 'fa_findDuplicates', 'Fuzzy::findDuplicates', 'fastphp_spike_findDuplicates', 'fastphp_glue_findDuplicates'],
];

// Same answers first: a fast wrong answer is worth nothing.
foreach ($rows as [$label, $php, $args, $entry, $name, $ext, $glue]) {
    $want = $php(...$args);
    if (Upstream::call($entry, $name, $args) !== $want || $ext(...$args) !== $want || $glue(...$args) !== $want) {
        fwrite(STDERR, "MISMATCH in $label\n");
        exit(1);
    }
}

$jit = function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false);
printf("PHP %s, JIT %s\n\n", PHP_VERSION, $jit ? 'on (tracing)' : 'off');
printf("| %-26s | %10s | %17s | %17s | %17s |\n", 'call', 'PHP', 'FFI + JSON', 'ext (generic)', 'ext (fast-php glue)');
printf("|%s|%s:|%s:|%s:|%s:|\n", str_repeat('-', 28), str_repeat('-', 11), str_repeat('-', 18), str_repeat('-', 18), str_repeat('-', 18));
foreach ($rows as [$label, $php, $args, $entry, $name, $ext, $glue]) {
    $p = per_call($php, $args);
    $f = per_call(fn(...$a) => Upstream::call($entry, $name, $a), $args);
    $e = per_call($ext, $args);
    $g = per_call($glue, $args);
    printf("| %-26s | %10s | %9s %6.1f× | %9s %6.1f× | %9s %6.1f× |\n", $label, fmt($p), fmt($f), $p / $f, fmt($e), $p / $e, fmt($g), $p / $g);
}
