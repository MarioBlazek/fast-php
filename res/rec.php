<?php
// fast-php's recorder, loaded with -d auto_prepend_file during `fast-php port`. A temporary
// prologue in the function being ported calls Rec::call(), which runs the original body and
// keeps the arguments and the outcome of up to FASTPHP_REC_MAX calls (reservoir sampling).
// At shutdown it derives smaller and edge-case inputs from what it kept, lets the original PHP
// answer them too, and writes everything to $FASTPHP_REC_DIR/rec-<pid>.jsonl.
//
// Values are stored with serialize(), which keeps int vs float, key types and order, and binary
// strings exactly. It never touches mt_rand(): the app's own random sequence stays the same.
// Reservoir sampling and the derived cases after fast-anything's rec.php (MIT).

namespace FastPhp\Runtime;

final class Rec
{
    private const BUDGET = 64 << 20;

    /** @var array<string, true> */
    private static array $active = [];
    /** @var array<string, int> */
    private static array $bytes = [];
    /** @var array<string, int> */
    private static array $seen = [];
    /** @var array<string, list<array<string, mixed>>> */
    private static array $kept = [];
    /** @var array<string, \Closure> */
    private static array $fns = [];
    /** @var array<string, \Closure|null> reads the properties an instance method uses, now */
    private static array $extras = [];
    /** @var array<string, int> how many of the recorded arguments the function itself takes */
    private static array $nargs = [];
    /** @var array<string, list<?\ReflectionType>> declared parameter types */
    private static array $types = [];

    /** The parameter types of the function whose prologue called Rec::call(). */
    private static function declaredTypes(): array
    {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] ?? null;
        try {
            $r = isset($frame['class']) ? new \ReflectionMethod($frame['class'], $frame['function']) : new \ReflectionFunction($frame['function'] ?? '');
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn (\ReflectionParameter $p) => $p->getType(), $r->getParameters());
    }

    /** Would this declared type take the value as it is (no coercion)? */
    private static function accepts(?\ReflectionType $t, mixed $v): bool
    {
        if ($t === null) {
            return true;
        }
        if ($v === null) {
            return $t->allowsNull();
        }
        $types = $t instanceof \ReflectionUnionType ? $t->getTypes() : [$t];
        foreach ($types as $one) {
            $name = $one instanceof \ReflectionNamedType ? strtolower($one->getName()) : 'mixed';
            $ok = match ($name) {
                'mixed' => true,
                'int' => is_int($v),
                'float' => is_float($v),
                'string' => is_string($v),
                'bool' => is_bool($v),
                'true' => $v === true,
                'false' => $v === false,
                'array', 'iterable' => is_array($v),
                default => false,
            };
            if ($ok) {
                return true;
            }
        }
        return false;
    }
    private static bool $hooked = false;

    /** False while a recorded call of $id runs, so its own body (and recursion) runs as is. */
    public static function on(string $id): bool
    {
        return !isset(self::$active[$id]);
    }

    /**
     * @param \Closure|null $extra for instance methods: returns the $this->properties the port
     *                              takes as extra arguments, read at the time of the call
     */
    public static function call(string $id, \Closure $fn, array $args, ?\Closure $extra = null): mixed
    {
        if (!isset(self::$types[$id])) {
            self::$types[$id] = self::declaredTypes();
        }
        // The latest closures: at shutdown, derived cases run against the latest object.
        self::$fns[$id] = $fn;
        self::$extras[$id] = $extra;
        self::$nargs[$id] = count($args);
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function([self::class, 'flush']);
        }
        $n = self::$seen[$id] = (self::$seen[$id] ?? 0) + 1;
        $max = (int) (getenv('FASTPHP_REC_MAX') ?: 500);
        $slot = null;
        if ($n <= $max) {
            $slot = $n - 1;
        } else {
            $j = random_int(0, $n - 1);
            $slot = $j < $max ? $j : null;
        }
        if ($slot === null) {
            return self::run($id, $fn, $args);
        }
        try {
            $in = serialize($extra !== null ? [...$args, ...$extra()] : $args);
        } catch (\Throwable) {
            return self::run($id, $fn, $args); // closures, resources: nothing a port can take
        }
        // A byte budget per function: 500 calls with megabytes of arguments each would not fit.
        if (isset(self::$kept[$id]) && (self::$bytes[$id] ?? 0) + strlen($in) > self::BUDGET) {
            return self::run($id, $fn, $args);
        }
        self::$bytes[$id] = (self::$bytes[$id] ?? 0) + strlen($in);
        try {
            $ret = self::run($id, $fn, $args);
        } catch (\Throwable $e) {
            self::$kept[$id][$slot] = ['args' => $in, 'exc' => [get_class($e), $e->getMessage()], 'warn' => self::$warnings];
            throw $e;
        }
        try {
            self::$kept[$id][$slot] = ['args' => $in, 'ret' => serialize($ret), 'warn' => self::$warnings];
        } catch (\Throwable) {
        }
        return $ret;
    }

    /** @var list<array{0: int, 1: string}> warnings, notices and deprecations of the running call */
    private static array $warnings = [];

    /** Runs the original, noting what it emits while still handing it to the app's own handler. */
    private static function run(string $id, \Closure $fn, array $args): mixed
    {
        self::$active[$id] = true;
        self::$warnings = [];
        $prev = null;
        $prev = set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0) use (&$prev) {
            if (error_reporting() & $no) {
                self::$warnings[] = [$no, $msg];
            }
            return $prev !== null ? $prev($no, $msg, $file, $line) : false;
        });
        try {
            return $fn(...$args);
        } finally {
            restore_error_handler();
            unset(self::$active[$id]);
        }
    }

    public static function flush(): void
    {
        $dir = getenv('FASTPHP_REC_DIR');
        if (!$dir) {
            return;
        }
        $lines = '';
        foreach (self::$kept as $id => $cases) {
            ksort($cases);
            foreach ($cases as $c) {
                $lines .= self::line($id, $c, false);
            }
            if (getenv('FASTPHP_REC_DERIVE') !== '0') {
                foreach (self::derive($id, array_values($cases)) as $c) {
                    $lines .= self::line($id, $c, true);
                }
            }
        }
        file_put_contents($dir . '/rec-' . getmypid() . '.jsonl', $lines, FILE_APPEND);
    }

    private static function line(string $id, array $c, bool $derived): string
    {
        return json_encode([
            'id' => $id,
            'derived' => $derived,
            'args' => base64_encode($c['args']),
            'ret' => isset($c['ret']) ? base64_encode($c['ret']) : null,
            'exc' => $c['exc'] ?? null,
            'warn' => $c['warn'] ?? [],
        ], JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /**
     * Smaller and edge-case inputs, one argument changed at a time, answered by the original.
     *
     * @param list<array<string, mixed>> $cases
     * @return list<array<string, mixed>>
     */
    private static function derive(string $id, array $cases): array
    {
        $fn = self::$fns[$id];
        $extra = self::$extras[$id] ?? null;
        $nargs = self::$nargs[$id];
        $out = [];
        $have = [];
        foreach ($cases as $c) {
            $have[$c['args']] = true;
        }
        $deadline = hrtime(true) + 20e9;
        // Derived cases run against the object as it is now: its properties are read again.
        $props = [];
        if ($extra !== null) {
            try {
                $props = $extra();
            } catch (\Throwable) {
                return [];
            }
        }
        $types = self::$types[$id] ?? [];
        foreach (array_slice($cases, 0, 5) as $c) {
            $args = array_slice(unserialize($c['args']), 0, $nargs);
            foreach ($args as $i => $v) {
                foreach (self::variants($v) as $alt) {
                    if (count($out) >= 100 || hrtime(true) > $deadline) {
                        return $out;
                    }
                    if (isset($types[$i]) && !self::accepts($types[$i], $alt)) {
                        continue; // the declared type would reject it before the function runs
                    }
                    $try = $args;
                    $try[$i] = $alt;
                    $in = serialize([...$try, ...$props]);
                    if (isset($have[$in])) {
                        continue;
                    }
                    $have[$in] = true;
                    // Derived calls run quietly: their warnings are recorded, not shown.
                    $level = error_reporting(0);
                    try {
                        $ret = self::run($id, $fn, $try);
                        $out[] = ['args' => $in, 'ret' => serialize($ret), 'warn' => self::$warnings];
                    } catch (\Throwable $e) {
                        $out[] = ['args' => $in, 'exc' => [get_class($e), $e->getMessage()], 'warn' => self::$warnings];
                    } finally {
                        error_reporting($level);
                    }
                }
            }
        }
        return $out;
    }

    /** Roughly half of a value: the first half of a string (by character) or array. */
    private static function shrink(mixed $v): mixed
    {
        if (is_string($v)) {
            $chars = preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($v);
            return implode('', array_slice($chars, 0, intdiv(count($chars), 2)));
        }
        if (is_array($v)) {
            return array_slice($v, 0, intdiv(count($v), 2), !array_is_list($v));
        }
        return is_int($v) ? intdiv($v, 2) : $v;
    }

    /** Upper case (Unicode too), and whitespace that trim() keeps (\f) or strips (\0) at the ends. */
    private static function decorate(string $s): string
    {
        $upper = function_exists('mb_strtoupper') && preg_match('//u', $s) ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
        return "\f " . str_replace(' ', " \t ", $upper) . "\0\n";
    }

    /** @return list<mixed> */
    private static function variants(mixed $v): array
    {
        if (is_array($v)) {
            // Small inputs only: the recorded calls already cover the real sizes.
            $n = count($v);
            $alt = [[]];
            foreach ([1, min(50, intdiv($n, 4)), min(300, intdiv($n, 2))] as $k) {
                if ($k > 0 && $k < $n) {
                    $alt[] = array_slice($v, 0, $k, !array_is_list($v));
                }
            }
            if (array_is_list($v) && $n > 2) {
                $gap = array_slice($v, 0, 50);
                unset($gap[intdiv(count($gap), 2)]); // a list with a hole is no longer a list
                $alt[] = $gap;
                $alt[] = array_combine(array_map(static fn ($k) => "k$k", array_keys(array_slice($v, 0, 20))), array_slice($v, 0, 20));
            }
            // The same shape with each element shrunk, and with each string decorated by the
            // whitespace and case that PHP's trim(), \s and strtolower() treat in their own way.
            if ($n > 0) {
                $head = array_slice($v, 0, 200, !array_is_list($v));
                $alt[] = array_slice(array_map(self::shrink(...), $head), 0, max(1, intdiv(count($head), 4)), !array_is_list($v));
                if (array_filter($head, is_string(...))) {
                    $alt[] = array_map(static fn ($x) => is_string($x) ? self::decorate($x) : $x, array_slice($head, 0, 40, !array_is_list($v)));
                }
            }
            return $alt;
        }
        if (is_string($v)) {
            // Plus numeric strings, which PHP compares and converts as numbers, and invalid UTF-8.
            return array_values(array_unique(['', ' ', self::shrink($v), self::decorate($v), '0', '1e3', ' 12', "\xff" . $v, strlen($v) < 2000 ? str_repeat($v, 50) : $v]));
        }
        if (is_int($v)) {
            return array_values(array_unique(array_filter([0, 1, -1, $v + 1, $v - 1, PHP_INT_MAX, PHP_INT_MIN], is_int(...))));
        }
        if (is_float($v)) {
            return [0.0, -0.0, 1.5, $v * 2, 0.1 + 0.2, 1e300, -INF, NAN];
        }
        if (is_bool($v)) {
            return [!$v];
        }
        return [];
    }
}
