<?php
// Differential fuzzing of fastphp-std against the running PHP:
//
//     php -n -d extension=libstd_check.so tests/std/fuzz.php [seed] [runs-per-function] [filter]
//
// Every check generates arguments, calls PHP's builtin and the Rust version
// (fastphp_std\call), and compares them with StrictComparator. An exception must match in
// class and message. When PHP warns, the Rust version must decline (PHP then runs the call
// itself); it may also decline other inputs, which is safe and only counted.
declare(strict_types=1);

require __DIR__ . '/../../src/Verify/StrictComparator.php';

use FastPhp\Verify\StrictComparator;

$seed = (int) ($argv[1] ?? random_int(1, PHP_INT_MAX >> 1));
$runs = (int) ($argv[2] ?? 1000);
$filter = $argv[3] ?? null;
mt_srand($seed);

function pick(array $a): mixed
{
    return $a[mt_rand(0, count($a) - 1)];
}

function chance(int $percent): bool
{
    return mt_rand(1, 100) <= $percent;
}

/** Strings built from the pieces PHP and Rust disagree about. */
function str(int $max = 12): string
{
    static $pieces = [
        'a', 'b', 'c', 'x', 'A', 'B', 'Z', 'é', 'É', 'ë', 'ß', 'Σ', 'σ', 'İ', 'ı', 'Ł', 'ł', 'ü', 'Ü', '日', '🙂',
        ' ', '  ', "\t", "\n", "\r", "\0", "\x0B", "\x0C", "\xA0", "\xC2\xA0",
        '0', '1', '9', '10', '-', '+', '.', ',', 'e', 'E', '_', '/', '\\', '$', '(', ')', '[', ']', '*', '?',
        "\xff", "\xc3", "\x80",
    ];
    $n = mt_rand(0, $max);
    $s = '';
    for ($i = 0; $i < $n; $i++) {
        $s .= pick($pieces);
    }
    return $s;
}

function ascii(int $max = 12): string
{
    $n = mt_rand(0, $max);
    $s = '';
    for ($i = 0; $i < $n; $i++) {
        $s .= pick(['a', 'b', 'c', 'ab', 'A', 'B', ' ', '-', '1', '2', '.']);
    }
    return $s;
}

function utf8(int $max = 10): string
{
    $s = str($max);
    return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'UTF-8');
}

function numstr(): string
{
    return pick([
        '0', '1', '-1', '12', ' 12', '12 ', "\t12\n", '+5', '-0', '00012', '1.5', '.5', '5.', '-.5', '1e3', '1E3',
        '1e-3', '1e', '1e+', '12abc', 'abc', '', ' ', '0x1A', '0b11', '9223372036854775807', '9223372036854775808',
        '-9223372036854775808', '-9223372036854775809', '99999999999999999999', '1e400', '-1e400', '1_000',
        '١٢', 'INF', 'NAN', '1.0', '1.50', '0.1', (string) mt_rand(-1000, 1000), mt_rand(-999, 999) . '.' . mt_rand(0, 999),
    ]);
}

function int(): int
{
    return pick([
        0, 1, -1, 2, 3, 7, 10, -10, 100, 255, 1000, mt_rand(-100, 100), mt_rand(-1000000, 1000000), mt_rand(),
        -mt_rand(), PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1, 4611686018427387904, 3037000499, 3037000500,
    ]);
}

function small(int $lo = -10, int $hi = 10): int
{
    return mt_rand($lo, $hi);
}

function float(): float
{
    $decimal = static fn () => (float) (mt_rand(-99999, 99999) . '.' . str_pad((string) mt_rand(0, 999), mt_rand(1, 3), '0', STR_PAD_LEFT));
    return pick([
        0.0, -0.0, 1.0, -1.0, 0.5, -0.5, 1.5, 2.5, -2.5, 0.285, 1.955, 5.045, 5.055, 1.005, 0.1 + 0.2, 1 / 3, 2 / 3,
        1e14, 1e15, 1e16, 1e17, 1e22, 1e23, 123456789012345.678, 1e-4, 1e-5, 1.5e-7, 4503599627370496.0, 4503599627370497.0,
        9007199254740993.0, 9.2233720368547758E+18, -9.2233720368547758E+18, 1.7976931348623157E+308, 5e-324,
        INF, -INF, NAN, 0.49999999999999994, 1234.5678, 0.045, 0.0045, 1000000.5, 999999.995,
        $decimal(), $decimal(), $decimal(), mt_rand() / mt_getrandmax(), mt_rand(-1000, 1000) / 8, mt_rand(-1000, 1000) / 3,
    ]);
}

function num(): int|float
{
    return chance(50) ? int() : float();
}

function scalar(): mixed
{
    return match (mt_rand(0, 6)) {
        0 => null,
        1 => (bool) mt_rand(0, 1),
        2 => int(),
        3 => float(),
        4 => numstr(),
        default => ascii(4),
    };
}

/** A list of one kind, sometimes mixed. */
function values(): array
{
    $gen = pick([int(...), float(...), numstr(...), static fn () => ascii(4), scalar(...)]);
    $n = mt_rand(0, 8);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $gen();
    }
    return $out;
}

function map(): array
{
    $out = [];
    $n = mt_rand(0, 7);
    for ($i = 0; $i < $n; $i++) {
        $k = pick([small(-3, 10), ascii(3), numstr()]);
        $out[$k] = scalar();
    }
    return $out;
}

function sep(): string
{
    return pick(['.', ',', '', ' ', "'", '··', 'ab']);
}

function pattern(): string
{
    return pick([
        '/\s+/', '/\s+/u', '//', '//u', '/x*/', '/a|/', '/\b/', '/(?=a)/', '/(a)(b)?(c)?/', '/(?<word>\w+)/', '/(\d+)-(\d+)/',
        '/[aeiou]/i', '/^a/m', '/a$/m', '/./s', '/./u', '/\w+/u', '/\p{Lu}/u', '/[[:alpha:]]+/', '/(?<=a)b/', '/a(?!b)/',
        '/ a b # comment
         /x', '#/#', '{a}', '(a(b)c)', '[\d]', '/a+?/', '/a+/U', '/(a)|(b)/', '/(?:x)?/', '/é/', '/É/iu', '/ß/iu', '/\x{0130}/iu',
        '/a/D', '/a/e', '/a', 'abc', '/[/', '/(?<n>a)|(?<n>b)/J', '/a/A', '/\d/n', '/(.)\1/', '/\K/', '/./', "/\xff/",
    ]);
}

function replacement(): string
{
    return pick(['', 'x', '$0', '$1', '\1', '${1}x', '${12}', '$12', '\\\\1', '\$1', '$', '\\', '[$2]', '${word}', '$1$1', 'a\\b']);
}

$checks = [
    'trim' => [static fn () => [str()], static fn ($s) => trim($s)],
    'trim/chars' => [static fn () => [str(), pick(['a', 'ab', 'a..c', 'a..', '..a', 'c..a', ' ', "\0..\x20", 'z..a', '...', 'é'])], static fn ($s, $c) => trim($s, $c), 'trim'],
    'ltrim' => [static fn () => [str()], static fn ($s) => ltrim($s)],
    'rtrim' => [static fn () => [str(), pick([' ', 'a..z', "\n"])], static fn ($s, $c) => rtrim($s, $c)],
    'strtolower' => [static fn () => [str()], static fn ($s) => strtolower($s)],
    'strtoupper' => [static fn () => [str()], static fn ($s) => strtoupper($s)],
    'ucfirst' => [static fn () => [str()], static fn ($s) => ucfirst($s)],
    'lcfirst' => [static fn () => [str()], static fn ($s) => lcfirst($s)],
    'ucwords' => [static fn () => [str(), chance(50) ? null : pick(['-', ' -', 'a..c', '..', ''])], static fn ($s, $d) => $d === null ? ucwords($s) : ucwords($s, $d)],
    'str_pad' => [static fn () => [str(5), small(-2, 14), pick(['0', ' ', 'ab', '', 'é']), small(-1, 3)], static fn ($s, $l, $p, $t) => str_pad($s, $l, $p, $t)],
    'substr' => [static fn () => [str(), small(), chance(30) ? null : small()], static fn ($s, $o, $l) => substr($s, $o, $l)],
    'strrev' => [static fn () => [str()], static fn ($s) => strrev($s)],
    'str_repeat' => [static fn () => [str(3), small(-1, 4)], static fn ($s, $n) => str_repeat($s, $n)],
    'str_replace' => [static fn () => [pick(['a', 'ab', '', ' ', 'é', "\0"]), pick(['', 'x', 'aa', '$1']), str()], static fn ($a, $b, $c) => str_replace($a, $b, $c)],
    'str_ireplace' => [static fn () => [pick(['a', 'AB', '', 'é']), pick(['', 'x']), str()], static fn ($a, $b, $c) => str_ireplace($a, $b, $c)],
    'explode' => [static fn () => [pick([',', ' ', 'ab', '', "\0"]), pick([str(), 'a,b,,c', ',', 'ab ab'])  , pick([PHP_INT_MAX, 0, 1, 2, 3, -1, -2, -5, PHP_INT_MIN])], static fn ($d, $s, $l) => explode($d, $s, $l)],
    'implode' => [static fn () => [pick([',', '', ', ']), values()], static fn ($g, $v) => implode($g, $v)],
    'strpos' => [static fn () => [str(), pick(['a', '', ' ', 'é', 'ab']), small()], static fn ($h, $n, $o) => strpos($h, $n, $o)],
    'stripos' => [static fn () => [str(), pick(['a', 'A', '', 'É']), small()], static fn ($h, $n, $o) => stripos($h, $n, $o)],
    'strrpos' => [static fn () => [str(), pick(['a', '', ' ', 'ab', '1']), small()], static fn ($h, $n, $o) => strrpos($h, $n, $o)],
    'substr_count' => [static fn () => [str(), pick(['a', '', ' ', 'aa'])], static fn ($h, $n) => substr_count($h, $n)],
    'str_contains' => [static fn () => [str(), pick(['a', '', 'é'])], static fn ($h, $n) => str_contains($h, $n)],
    'str_starts_with' => [static fn () => [str(), pick(['a', '', 'é'])], static fn ($h, $n) => str_starts_with($h, $n)],
    'str_ends_with' => [static fn () => [str(), pick(['a', '', 'é'])], static fn ($h, $n) => str_ends_with($h, $n)],
    'str_split' => [static fn () => [str(), small(-1, 4)], static fn ($s, $n) => str_split($s, $n)],
    'levenshtein' => [static fn () => [str(8), str(8)], static fn ($a, $b) => levenshtein($a, $b)],
    'similar_text' => [static fn () => [str(8), str(8)], static function ($a, $b) {
        $n = similar_text($a, $b, $p);
        return [$n, $p];
    }],
    'number_format' => [static fn () => [num(), small(-6, 12), sep(), sep()], static fn ($n, $d, $p, $t) => number_format($n, $d, $p, $t)],
    'float_to_string' => [static fn () => [float()], static fn ($f) => (string) $f],
    'float_export' => [static fn () => [float()], static fn ($f) => var_export($f, true)],
    'is_numeric' => [static fn () => [numstr()], static fn ($s) => is_numeric($s)],
    'str_to_int' => [static fn () => [numstr()], static fn ($s) => (int) $s],
    'str_to_float' => [static fn () => [numstr()], static fn ($s) => (float) $s],
    'float_to_int' => [static fn () => [float()], static fn ($f) => (int) $f],
    'round' => [static fn () => [float(), small(-5, 15)], static fn ($f, $p) => round($f, $p)],
    'add' => [static fn () => [num(), num()], static fn ($a, $b) => $a + $b],
    'sub' => [static fn () => [num(), num()], static fn ($a, $b) => $a - $b],
    'mul' => [static fn () => [num(), num()], static fn ($a, $b) => $a * $b],
    'div' => [static fn () => [num(), pick([num(), 0, 0.0, 1, -1, 2, 3])], static fn ($a, $b) => $a / $b],
    'rem' => [static fn () => [int(), pick([int(), 0, 1, -1, 3, -3])], static fn ($a, $b) => $a % $b],
    'intdiv' => [static fn () => [int(), pick([int(), 0, 1, -1, 3])], static fn ($a, $b) => intdiv($a, $b)],
    'pow' => [static fn () => [pick([0, 1, -1, 2, -2, 3, 10, 7, int()]), small(-3, 70)], static fn ($a, $b) => $a ** $b],
    'abs' => [static fn () => [num()], static fn ($n) => abs($n)],
    'compare' => [static fn () => [scalar(), scalar()], static fn ($a, $b) => $a <=> $b],
    'compare/arrays' => [static fn () => [map(), map()], static fn ($a, $b) => $a <=> $b, 'compare'],
    'loose_eq' => [static fn () => [scalar(), scalar()], static fn ($a, $b) => $a == $b],
    'sort' => [static fn () => [values()], static function ($v) {
        sort($v);
        return $v;
    }],
    'asort' => [static fn () => [map()], static function ($v) {
        asort($v);
        return $v;
    }],
    'ksort' => [static fn () => [map()], static function ($v) {
        ksort($v);
        return $v;
    }],
    'mb_strlen' => [static fn () => [str()], static fn ($s) => mb_strlen($s)],
    'mb_substr' => [static fn () => [utf8(), small(), chance(30) ? null : small()], static fn ($s, $o, $l) => mb_substr($s, $o, $l)],
    'mb_strtolower' => [static fn () => [utf8()], static fn ($s) => mb_strtolower($s)],
    'mb_strtoupper' => [static fn () => [utf8()], static fn ($s) => mb_strtoupper($s)],
    'mb_str_split' => [static fn () => [str(), small(-1, 3)], static fn ($s, $n) => mb_str_split($s, $n)],
    'preg_match' => [static fn () => [pattern(), str()], static function ($p, $s) {
        $r = preg_match($p, $s, $m);
        return $r === false ? throw new RuntimeException('false') : ($r ? $m : false);
    }],
    'preg_match_all' => [static fn () => [pattern(), str()], static function ($p, $s) {
        $r = preg_match_all($p, $s, $m);
        return $r === false ? throw new RuntimeException('false') : [$r, $m];
    }],
    'preg_replace' => [static fn () => [pattern(), replacement(), str(), pick([-1, -1, 1, 2, 0])], static function ($p, $r, $s, $l) {
        $out = preg_replace($p, $r, $s, $l, $n);
        return $out === null ? throw new RuntimeException('null') : [$out, $n];
    }],
    'preg_split' => [static fn () => [pattern(), str(), pick([-1, 0, 1, 2, 3]), small(0, 3)], static function ($p, $s, $l, $f) {
        $r = preg_split($p, $s, $l, $f);
        return $r === false ? throw new RuntimeException('false') : $r;
    }],
    'preg_quote' => [static fn () => [str(), chance(50) ? null : pick(['/', '#', 'a'])], static fn ($s, $d) => preg_quote($s, $d)],
    'crc32' => [static fn () => [str(20)], static fn ($s) => crc32($s)],
    'md5' => [static fn () => [str(20)], static fn ($s) => md5($s)],
    'sha1' => [static fn () => [str(20)], static fn ($s) => sha1($s)],
    'range' => [static fn () => [small(-20, 20), small(-20, 20), small(-2, 6)], static fn ($a, $b, $s) => range($a, $b, $s)],
    'array_sum' => [static fn () => [pick([[int(), int()], [float(), int()], values(), [PHP_INT_MAX, 1], [1, 2, 3]])], static fn ($v) => array_sum($v)],
    'array_unique' => [static fn () => [map() + values()], static fn ($v) => array_unique($v)],
    'array_slice' => [static fn () => [map() + values(), small(-5, 5), chance(30) ? null : small(-5, 5), (bool) mt_rand(0, 1)], static fn ($a, $o, $l, $p) => array_slice($a, $o, $l, $p)],
];

$failed = 0;
$t0 = microtime(true);
printf("fastphp-std vs PHP %s, seed %d, %d runs per check\n\n", PHP_VERSION, $seed, $runs);
foreach ($checks as $label => $check) {
    if ($filter !== null && !str_contains($label, $filter)) {
        continue;
    }
    [$gen, $php] = $check;
    $fn = $check[2] ?? $label;
    $ok = $declined = 0;
    $bad = [];
    for ($i = 0; $i < $runs; $i++) {
        $args = $gen();
        // PHP's answer, and whether it warned.
        $warning = null;
        set_error_handler(static function (int $no, string $msg) use (&$warning) {
            $warning ??= $msg;
            return true;
        });
        $want = $wantExc = null;
        try {
            $want = $php(...$args);
        } catch (Throwable $e) {
            $wantExc = $e;
        }
        restore_error_handler();
        // Ours.
        $got = $gotExc = null;
        try {
            $got = fastphp_std\call($fn, $args);
        } catch (Throwable $e) {
            $gotExc = $e;
        }
        $declinedNow = $gotExc !== null && $gotExc->getMessage() === 'DECLINED';
        $why = null;
        if ($declinedNow) {
            $declined++;
            continue;
        } elseif ($warning !== null) {
            $why = "PHP warned (\"$warning\"), Rust didn't decline";
        } elseif ($wantExc instanceof RuntimeException && in_array($wantExc->getMessage(), ['false', 'null'], true)) {
            $why = 'PHP failed (' . preg_last_error_msg() . "), Rust didn't decline";
        } elseif ($wantExc !== null || $gotExc !== null) {
            $w = $wantExc ? get_class($wantExc) . ': ' . $wantExc->getMessage() : 'no exception';
            $g = $gotExc ? get_class($gotExc) . ': ' . $gotExc->getMessage() : 'no exception';
            if ($w !== $g) {
                $why = "PHP: $w / Rust: $g";
            }
        } else {
            $why = StrictComparator::diff($want, $got);
        }
        if ($why === null) {
            $ok++;
        } else {
            $bad[] = [$args, $why];
        }
    }
    $status = $bad ? 'FAIL' : 'ok  ';
    printf("%s %-16s %5d match  %5d declined  %5d differ\n", $status, $label, $ok, $declined, count($bad));
    foreach (array_slice($bad, 0, 3) as [$args, $why]) {
        echo '       ', $fn, '(', implode(', ', array_map(static fn ($a) => var_export($a, true), $args)), "): $why\n";
    }
    $failed += count($bad);
}
printf("\n%s in %.1fs (seed %d)\n", $failed ? "$failed differences" : 'no differences', microtime(true) - $t0, $seed);
exit($failed ? 1 : 0);
