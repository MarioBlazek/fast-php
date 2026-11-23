<?php
// Phase 0: every value goes PHP -> Rust -> PHP and must come back identical (===, key order, key types).
// The same values through JSON (what fast-anything's FFI bridge does) are shown for contrast.
//   php -d extension=rust/target/release/libspike_ext.dylib spike/roundtrip.php
declare(strict_types=1);

$cases = [
    'null' => null, 'true' => true, 'false' => false,
    'int' => 42, 'negative int' => -7, 'PHP_INT_MAX' => PHP_INT_MAX, 'PHP_INT_MIN' => PHP_INT_MIN,
    'float' => 1.5, 'whole float' => 3.0, '-0.0' => -0.0, 'INF' => INF, '-INF' => -INF, 'tiny float' => 5e-324,
    'empty string' => '', 'utf-8' => 'Zoë Łukasz 東京 🦀', 'binary string' => "\xff\x00\xfe", 'NUL inside' => "a\0b",
    'empty array' => [], 'list' => [1, 2, 3], 'list with gap' => [0 => 'a', 2 => 'b'],
    'reversed int keys' => [2 => 'x', 1 => 'y'], 'negative key' => [-5 => 'n'],
    'string keys' => ['b' => 1, 'a' => 2], 'numeric-looking string keys' => ['01' => 'a', '1.5' => 'b', ' 1' => 'c'],
    'mixed keys' => [5 => 'five', 'x' => 'ex', 'six'], 'binary key' => ["\xff" => 1],
    'nested' => ['rows' => [[1, 2.0, 'three'], ['k' => [null, false]]], 'meta' => ['n' => 2]],
    'int vs float in list' => [1, 1.0, '1'],
];

$fails = 0;
$jsonLoses = 0;
foreach ($cases as $name => $v) {
    $ok = true;
    foreach (['fastphp_spike_roundtrip', 'fastphp_glue_roundtrip'] as $fn) {
        $back = $fn($v);
        $same = $back === $v;
        if ($same && is_float($v) && $v === 0.0) $same = fdiv(1, $v) === fdiv(1, $back); // keep the sign of zero
        $ok = $ok && $same;
    }
    if (!$ok) { $fails++; echo "FAIL  $name: ", var_export($v, true), ' came back as ', var_export($back, true), "\n"; }
    $json = json_encode($v, JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $viaJson = json_decode((string) $json, true);
    if ($viaJson !== $v) $jsonLoses++;
    printf("%-4s %-28s json %s\n", $ok ? 'ok' : 'FAIL', $name, $viaJson === $v ? 'ok' : 'LOSES IT');
}

// Ports throw PHP's own exception classes.
foreach (['ValueError', 'DivisionByZeroError', 'InvalidArgumentException', 'No\\Such\\Class'] as $class) {
    try {
        fastphp_spike_throw($class);
        $fails++; echo "FAIL  $class: nothing thrown\n";
    } catch (Throwable $e) {
        $want = class_exists($class) ? $class : 'Exception';
        $ok = get_class($e) === $want;
        $fails += $ok ? 0 : 1;
        printf("%-4s throws %-26s -> %s: %s\n", $ok ? 'ok' : 'FAIL', $class, get_class($e), $e->getMessage());
    }
}

// Objects aren't ports' business: they must be refused, not mangled.
foreach (['fastphp_spike_roundtrip', 'fastphp_glue_roundtrip'] as $fn) {
    try {
        $fn(new stdClass());
        $fails++; echo "FAIL  $fn accepted an object\n";
    } catch (TypeError $e) {
        echo "ok   $fn refuses objects: ", $e->getMessage(), "\n";
    }
}
// A gap left by unset() is skipped, like foreach does.
$holes = [1, 2, 3, 4];
unset($holes[1]);
$fails += fastphp_glue_roundtrip($holes) === $holes ? 0 : 1;
$fails += fastphp_glue_sum($holes) === 8 ? 0 : 1;
echo "ok   arrays with unset() holes\n";

printf("\n%d cases, %d failed through the extension, %d lost through JSON\n", count($cases), $fails, $jsonLoses);
exit($fails ? 1 : 0);
