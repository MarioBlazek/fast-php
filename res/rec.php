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
    /** @var array<string, true> */
    private static array $active = [];
    /** @var array<string, int> */
    private static array $seen = [];
    /** @var array<string, list<array<string, mixed>>> */
    private static array $kept = [];
    /** @var array<string, \Closure> */
    private static array $fns = [];
    private static bool $hooked = false;

    /** False while a recorded call of $id runs, so its own body (and recursion) runs as is. */
    public static function on(string $id): bool
    {
        return !isset(self::$active[$id]);
    }

    public static function call(string $id, \Closure $fn, array $args): mixed
    {
        self::$fns[$id] ??= $fn;
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
            $in = serialize($args);
        } catch (\Throwable) {
            return self::run($id, $fn, $args); // closures, resources: nothing a port can take
        }
        try {
            $ret = self::run($id, $fn, $args);
        } catch (\Throwable $e) {
            self::$kept[$id][$slot] = ['args' => $in, 'exc' => [get_class($e), $e->getMessage()]];
            throw $e;
        }
        try {
            self::$kept[$id][$slot] = ['args' => $in, 'ret' => serialize($ret)];
        } catch (\Throwable) {
        }
        return $ret;
    }

    private static function run(string $id, \Closure $fn, array $args): mixed
    {
        self::$active[$id] = true;
        try {
            return $fn(...$args);
        } finally {
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
        ]) . "\n";
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
        $out = [];
        $have = [];
        foreach ($cases as $c) {
            $have[$c['args']] = true;
        }
        $deadline = hrtime(true) + 20e9;
        foreach (array_slice($cases, 0, 5) as $c) {
            $args = unserialize($c['args']);
            foreach ($args as $i => $v) {
                foreach (self::variants($v) as $alt) {
                    if (count($out) >= 60 || hrtime(true) > $deadline) {
                        return $out;
                    }
                    $try = $args;
                    $try[$i] = $alt;
                    $in = serialize($try);
                    if (isset($have[$in])) {
                        continue;
                    }
                    $have[$in] = true;
                    try {
                        $out[] = ['args' => $in, 'ret' => serialize(self::run($id, $fn, $try))];
                    } catch (\Throwable $e) {
                        $out[] = ['args' => $in, 'exc' => [get_class($e), $e->getMessage()]];
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
            $n = count($v);
            $alt = [[]];
            foreach ([1, intdiv($n, 4), intdiv($n, 2)] as $k) {
                if ($k > 0 && $k < $n) {
                    $alt[] = array_slice($v, 0, $k, !array_is_list($v));
                }
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
            return array_values(array_unique(['', ' ', self::shrink($v), self::decorate($v)]));
        }
        if (is_int($v)) {
            return array_values(array_unique(array_filter([0, 1, -1, $v + 1, $v - 1], is_int(...))));
        }
        if (is_float($v)) {
            return [0.0, -0.0, 1.5, $v * 2];
        }
        if (is_bool($v)) {
            return [!$v];
        }
        return [];
    }
}
