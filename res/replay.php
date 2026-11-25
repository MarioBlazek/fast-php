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
        'warn' => $c['warn'] ?? [],
    ];
}
$looseMessages = (bool) ($cfg['looseMessages'] ?? false);

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
        if ($c['warn']) {
            // PHP warned here; a port can't, so it must decline the input and let PHP run.
            $why = 'PHP emitted "' . $c['warn'][0][1] . '" for this input; decline it with PhpError::fallback() so PHP runs and warns';
        } else {
            $why = $c['exc'] !== null
                ? "expected {$c['exc'][0]}: {$c['exc'][1]}, got a result " . StrictComparator::short($got)
                : StrictComparator::diff($c['ret'], $got);
        }
    } catch (Throwable $e) {
        if ($c['exc'] !== null && get_class($e) === $c['exc'][0] && !$c['warn']) {
            $why = null;
            if ($e->getMessage() !== $c['exc'][1]) {
                $msg = "same exception class, different message: \"{$e->getMessage()}\", expected \"{$c['exc'][1]}\"";
                $looseMessages ? $warnings[] = "case #$i: $msg" : $why = $msg;
            }
        } else {
            $want = $c['warn'] ? 'a fallback (PHP warned: "' . $c['warn'][0][1] . '")' : ($c['exc'] !== null ? "{$c['exc'][0]}: {$c['exc'][1]}" : 'result ' . StrictComparator::short($c['ret']));
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
$benchSkipped = null;
if ($cfg['original'] !== null) {
    if ($cfg['original']['autoload'] ?? null) {
        require $cfg['original']['autoload'];
    }
    require $cfg['original']['file'];
    [$class, $name] = $cfg['original']['class'] !== null ? [$cfg['original']['class'], $cfg['original']['function']] : [null, $cfg['original']['function']];
    if ($cfg['original']['instance']) {
        // One object per case, built without its constructor, with the recorded properties
        // set from inside the class (readonly ones too). Built before anything is timed.
        $n = $cfg['original']['nargs'];
        $props = $cfg['original']['props'];
        $make = Closure::bind(static function (array $values) use ($class, $props) {
            $o = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            foreach ($props as $i => $p) {
                $o->$p = $values[$i];
            }
            return $o;
        }, null, $class);
        $call = Closure::bind(static fn (object $o, array $a) => $o->$name(...$a), null, $class);
        $objects = [];
        foreach ($cases as $i => $c) {
            $objects[$i] = $make(array_slice($c['args'], $n));
        }
        $original = static function (...$a) use ($call, $objects, $n, &$current) {
            return $call($objects[$current], array_slice($a, 0, $n));
        };
    } else {
        $original = $class !== null
            ? Closure::bind(static fn (...$a) => self::$name(...$a), null, $class)
            : static fn (...$a) => $name(...$a);
    }
    $prologue = $cfg['fallback']
        ? static fn (...$a) => $native(...$a) ?? $original(...$a)
        : static fn (...$a) => $native(...$a);
    $current = 0;
    $time = static function (Closure $f) use ($cases, &$current): float {
        $t = hrtime(true);
        foreach ($cases as $i => $c) {
            $current = $i;
            try {
                $f(...$c['args']);
            } catch (Throwable) {
            }
        }
        return (hrtime(true) - $t) / 1e9;
    };
    // The original on its own must give the recorded answers, or its timings mean nothing
    // (a class it needs may not load outside the app, for one).
    foreach ($cases as $i => $c) {
        $current = $i;
        try {
            $got = $original(...$c['args']);
            $ok = $c['exc'] === null && StrictComparator::diff($c['ret'], $got) === null;
        } catch (Throwable $e) {
            $ok = $c['exc'] !== null && get_class($e) === $c['exc'][0];
            $got = $e;
        }
        if (!$ok && !$c['warn']) {
            $benchSkipped = 'the original, loaded on its own, ' . ($got instanceof Throwable ? 'threw ' . get_class($got) . ': ' . $got->getMessage() : "doesn't reproduce case #$i");
            break;
        }
    }
    // Interleaved rounds until each side has had about half the budget; at least one round.
    $php = $nat = 0.0;
    $rounds = 0;
    $deadline = microtime(true) + $cfg['budget'];
    do {
        if ($benchSkipped !== null) {
            break;
        }
        $php += $time($original);
        $nat += $time($prologue);
        $rounds++;
    } while (microtime(true) < $deadline && $rounds < 1000);
    $calls = $rounds * count($cases);
    $bench = $benchSkipped !== null ? null : ['rounds' => $rounds, 'php' => $php / $calls, 'native' => $nat / $calls, 'jit' => (bool) (ini_get('opcache.jit') && ini_get('opcache.jit') !== 'disable' && function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false))];
}

echo json_encode([
    'total' => count($cases),
    'passed' => $passed,
    'fellBack' => $fellBack,
    'failed' => count($failures),
    'failures' => array_values(array_filter($failures)),
    'warnings' => array_slice($warnings, 0, 10),
    'bench' => $bench,
    'benchSkipped' => $benchSkipped,
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
