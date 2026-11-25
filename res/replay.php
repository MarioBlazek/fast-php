<?php
// fast-php verify, the PHP side:  php -n -d extension=<port> replay.php <config.json>
//
// Calls the native function with every recorded case and compares with what the original PHP
// returned (StrictComparator: `===`, NAN equal, -0.0 not 0.0). An exception must have the same
// class. Then, when the original can be loaded on its own, it times the prologue path (native,
// and PHP when the native declines) against the original over all cases.
//
// Prints one JSON object on stdout.
declare(strict_types=1);

require __DIR__ . '/../src/Verify/StrictComparator.php';

use FastPhp\Verify\StrictComparator;

$cfg = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$native = $cfg['native'];
if (!function_exists($native)) {
    echo json_encode(['error' => "the extension doesn't define $native()"]), "\n";
    exit(1);
}

$cases = [];
foreach (file($cfg['cases'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $c = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    $cases[] = [
        'derived' => $c['derived'],
        'args' => unserialize(base64_decode($c['args'])),
        'ret' => $c['ret'] !== null ? unserialize(base64_decode($c['ret'])) : null,
        'exc' => $c['exc'],
    ];
}

$passed = 0;
$fellBack = 0;
$failures = [];
$warnings = [];
foreach ($cases as $i => $c) {
    try {
        $got = $native(...$c['args']);
        if ($got === null && $cfg['fallback']) {
            $fellBack++; // the original PHP runs for this input, so it is right by construction
            continue;
        }
        $why = $c['exc'] !== null
            ? "expected {$c['exc'][0]}: {$c['exc'][1]}, got a result " . StrictComparator::short($got)
            : StrictComparator::diff($c['ret'], $got);
    } catch (Throwable $e) {
        if ($c['exc'] !== null && get_class($e) === $c['exc'][0]) {
            if ($e->getMessage() !== $c['exc'][1]) {
                $warnings[] = "case #$i: same exception class, different message: \"{$e->getMessage()}\" vs \"{$c['exc'][1]}\"";
            }
            $why = null;
        } else {
            $want = $c['exc'] !== null ? "{$c['exc'][0]}: {$c['exc'][1]}" : 'result ' . StrictComparator::short($c['ret']);
            $why = 'threw ' . get_class($e) . ': ' . $e->getMessage() . ", expected $want";
        }
    }
    if ($why === null) {
        $passed++;
    } elseif (count($failures) < 20) {
        $failures[] = ['case' => $i, 'derived' => $c['derived'], 'args' => array_map([StrictComparator::class, 'short'], $c['args']), 'why' => $why];
    } else {
        $failures[] = null;
    }
}

$bench = null;
if ($cfg['original'] !== null) {
    require $cfg['original']['file'];
    [$class, $name] = $cfg['original']['class'] !== null ? [$cfg['original']['class'], $cfg['original']['function']] : [null, $cfg['original']['function']];
    $original = $class !== null
        ? Closure::bind(static fn (...$a) => self::$name(...$a), null, $class)
        : static fn (...$a) => $name(...$a);
    $prologue = $cfg['fallback']
        ? static fn (...$a) => $native(...$a) ?? $original(...$a)
        : static fn (...$a) => $native(...$a);
    $time = static function (Closure $f) use ($cases): float {
        $t = hrtime(true);
        foreach ($cases as $c) {
            try {
                $f(...$c['args']);
            } catch (Throwable) {
            }
        }
        return (hrtime(true) - $t) / 1e9;
    };
    // Interleaved rounds until each side has had about half the budget; at least one round.
    $php = $nat = 0.0;
    $rounds = 0;
    $deadline = microtime(true) + $cfg['budget'];
    do {
        $php += $time($original);
        $nat += $time($prologue);
        $rounds++;
    } while (microtime(true) < $deadline && $rounds < 1000);
    $calls = $rounds * count($cases);
    $bench = ['rounds' => $rounds, 'php' => $php / $calls, 'native' => $nat / $calls, 'jit' => (bool) (ini_get('opcache.jit') && ini_get('opcache.jit') !== 'disable' && function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false))];
}

echo json_encode([
    'total' => count($cases),
    'passed' => $passed,
    'fellBack' => $fellBack,
    'failed' => count($failures),
    'failures' => array_values(array_filter($failures)),
    'warnings' => array_slice($warnings, 0, 10),
    'bench' => $bench,
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
