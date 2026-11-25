<?php
declare(strict_types=1);

namespace FastPhp\Verify;

/**
 * Compares two values the way `===` does, and says where they differ. Two differences from
 * `===`: NAN equals NAN (the same computation gave the same result), and -0.0 differs from 0.0
 * (they print differently).
 *
 * No dependencies: res/replay.php loads this file on its own.
 */
final class StrictComparator
{
    /** Null when identical, else a description of the first difference. */
    public static function diff(mixed $want, mixed $got, string $path = 'result'): ?string
    {
        $tw = get_debug_type($want);
        $tg = get_debug_type($got);
        if ($tw !== $tg) {
            return "$path: expected $tw " . self::short($want) . ", got $tg " . self::short($got);
        }
        if (is_float($want)) {
            if (is_nan($want) && is_nan($got)) {
                return null;
            }
            if ($want === $got && ($want != 0.0 || fdiv(1, $want) === fdiv(1, $got))) {
                return null;
            }
            return "$path: expected " . self::short($want) . ', got ' . self::short($got);
        }
        if (is_array($want)) {
            $kw = array_keys($want);
            $kg = array_keys($got);
            foreach ($kw as $i => $k) {
                if (!array_key_exists($i, $kg)) {
                    return "$path: " . count($got) . ' elements, expected ' . count($want) . ' (missing key ' . var_export($k, true) . ')';
                }
                if ($kg[$i] !== $k) {
                    $where = array_key_exists($k, $got) ? 'keys in a different order' : 'missing key ' . var_export($k, true);
                    return "$path: $where: element #$i has key " . var_export($kg[$i], true) . ', expected ' . var_export($k, true);
                }
                $d = self::diff($want[$k], $got[$k], $path . '[' . var_export($k, true) . ']');
                if ($d !== null) {
                    return $d;
                }
            }
            if (count($kg) > count($kw)) {
                return "$path: " . count($got) . ' elements, expected ' . count($want) . ' (extra key ' . var_export($kg[count($kw)], true) . ')';
            }
            return null;
        }
        if (is_object($want)) {
            return $want == $got ? null : "$path: objects differ";
        }
        return $want === $got ? null : "$path: expected " . self::short($want) . ', got ' . self::short($got);
    }

    public static function short(mixed $v): string
    {
        $s = is_array($v) ? 'array(' . count($v) . ')' : (is_float($v) && is_finite($v) ? var_export($v, true) : (is_string($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : var_export($v, true)));
        return strlen($s) > 80 ? substr($s, 0, 80) . '…' : $s;
    }
}
