<?php
declare(strict_types=1);

namespace FastPhp\Port;

/**
 * Picks the Rust type of each argument and of the result, from the declared PHP types and the
 * values actually recorded.
 *
 * Narrow types are safe because the generated glue never coerces: when a call brings a value
 * outside the chosen type (a non-UTF-8 string for String, a gap in a list for Vec, an int for
 * f64), the native function returns null and the original PHP runs instead. That needs a
 * result that is never null; for functions that can return null, the glue has no fallback and
 * the types come from the declarations alone (array → PhpArray, untyped → PhpValue).
 *
 * A shape is 'never' | 'null' | 'bool' | 'int' | 'float' | 'string' | 'bytes' | 'mixed'
 * | ['list', shape] | ['map', shape] | ['?', shape], plus from PHPDoc (Analysis\DocTypes) 'str',
 * ['arr', shape] and ['tuple', [shape, ...]]. 'string' is valid UTF-8, 'bytes' and 'str' any string.
 * 'map' alone means ['map', 'mixed'].
 */
final class Types
{
    public static function shapeOf(mixed $v): string|array
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => 'bool',
            is_int($v) => 'int',
            is_float($v) => 'float',
            is_string($v) => preg_match('//u', $v) ? 'string' : 'bytes',
            is_array($v) => [array_is_list($v) ? 'list' : 'map', array_reduce($v, static fn ($s, $x) => self::join($s, self::shapeOf($x)), 'never')],
            default => 'mixed',
        };
    }

    public static function join(string|array $a, string|array $b): string|array
    {
        if ($a === $b || $b === 'never') {
            return $a;
        }
        if ($a === 'never') {
            return $b;
        }
        if ($a === 'null') {
            return is_array($b) && $b[0] === '?' ? $b : ['?', $b];
        }
        if ($b === 'null') {
            return self::join($b, $a);
        }
        if (is_array($a) && $a[0] === '?') {
            return ['?', self::join($a[1], is_array($b) && $b[0] === '?' ? $b[1] : $b)];
        }
        if (is_array($b) && $b[0] === '?') {
            return self::join($b, $a);
        }
        if (in_array($a, ['string', 'bytes'], true) && in_array($b, ['string', 'bytes'], true)) {
            return 'bytes';
        }
        $a = $a === 'map' ? ['map', 'mixed'] : $a;
        $b = $b === 'map' ? ['map', 'mixed'] : $b;
        if (is_array($a) && is_array($b) && in_array($a[0], ['list', 'map'], true) && in_array($b[0], ['list', 'map'], true)) {
            // Two lists stay a list; a list and a map are both arrays with any keys.
            return [$a[0] === 'list' && $b[0] === 'list' ? 'list' : 'map', self::join($a[1], $b[1])];
        }
        return 'mixed';
    }

    /** @param list<mixed> $values */
    public static function infer(array $values): string|array
    {
        return array_reduce($values, static fn ($s, $v) => self::join($s, self::shapeOf($v)), 'never');
    }

    public static function nullable(string|array $shape): bool
    {
        return $shape === 'null' || $shape === 'mixed' || $shape === 'never' || (is_array($shape) && $shape[0] === '?');
    }

    /** May a declared PHP type hold null? No declared type counts as yes. */
    public static function declaredNullable(?string $type): bool
    {
        if ($type === null) {
            return true;
        }
        $t = strtolower($type);
        return str_starts_with($t, '?') || $t === 'mixed' || $t === 'null' || in_array('null', explode('|', $t), true);
    }

    /** A PHPDoc shape with nothing recorded to refine it. */
    private static function docOnly(string|array $d): string|array
    {
        if ($d === 'str') {
            return 'bytes';
        }
        if (is_array($d)) {
            return match ($d[0]) {
                'tuple' => ['tuple', array_map(self::docOnly(...), $d[1])],
                'arr' => ['map', self::docOnly($d[1])],
                default => [$d[0], self::docOnly($d[1])],
            };
        }
        return $d;
    }

    /**
     * Combines a PHPDoc shape with the recorded one: PHPDoc gives the intent (array<string, int>
     * stays a map even when every recorded value happened to be a list), the recordings settle
     * what PHPDoc leaves open (UTF-8 or bytes, list or map for T[]). When they contradict each
     * other the recordings win, or the port would decline every real call.
     */
    public static function merge(string|array|null $doc, string|array $rec): string|array
    {
        if ($doc === null || $doc === 'mixed') {
            return $rec === 'never' ? 'mixed' : $rec;
        }
        if ($rec === 'never') {
            return self::docOnly($doc);
        }
        if ($doc === 'str') {
            return $rec === 'string' ? 'string' : ($rec === 'bytes' ? 'bytes' : (in_array($rec, ['null', 'bool', 'int', 'float'], true) || is_array($rec) ? $rec : 'bytes'));
        }
        if (!is_array($doc)) {
            return $doc === $rec ? $doc : $rec;
        }
        $rk = is_array($rec) ? $rec[0] : ($rec === 'map' ? 'map' : null);
        $rv = is_array($rec) ? $rec[1] : 'mixed';
        return match ($doc[0]) {
            '?' => match (true) {
                $rec === 'null' => ['?', self::docOnly($doc[1])],
                $rk === '?' => ['?', self::merge($doc[1], $rv)],
                default => ['?', self::merge($doc[1], $rec)],
            },
            'list' => $rk === 'list' ? ['list', self::merge($doc[1], $rv)] : $rec,
            'arr' => in_array($rk, ['list', 'map'], true) ? [$rk, self::merge($doc[1], $rv)] : $rec,
            'map' => in_array($rk, ['list', 'map'], true) ? ['map', self::merge($doc[1], $rv)] : $rec,
            'tuple' => $rk === 'list' ? ['tuple', array_map(static fn ($d) => self::merge($d, $rv), $doc[1])] : $rec,
            default => $rec,
        };
    }

    /**
     * The shape of a parameter: the declared type where it is exact, else what was recorded,
     * refined by PHPDoc.
     *
     * @param list<mixed> $samples
     */
    public static function param(?string $declared, array $samples, bool $fallback, string|array|null $doc = null): string|array
    {
        $t = $declared === null ? null : strtolower($declared);
        $scalar = ['int' => 'int', 'float' => 'float', 'bool' => 'bool', 'string' => 'string'];
        $base = $t !== null ? ltrim($t, '?') : null;
        if ($base !== null && isset($scalar[$base])) {
            $shape = $scalar[$base];
            if ($shape === 'string' && (!$fallback || self::infer($samples) !== 'string')) {
                $shape = 'bytes';
            }
            return str_starts_with($t, '?') ? ['?', $shape] : $shape;
        }
        if (!$fallback) {
            return match ($t) {
                'array' => 'map',
                '?array' => ['?', 'map'],
                default => 'mixed',
            };
        }
        return self::merge($doc, self::infer($samples));
    }

    public static function rust(string|array $shape): string
    {
        if (is_array($shape)) {
            $inner = $shape[0] === 'tuple' ? null : ($shape[1] === 'never' ? 'mixed' : $shape[1]);
            return match ($shape[0]) {
                'list' => 'Vec<' . self::rust($inner) . '>',
                'map', 'arr' => $inner === 'mixed' ? 'PhpArray' : 'Map<' . self::rust($inner) . '>',
                'tuple' => '(' . implode(', ', array_map(self::rust(...), $shape[1])) . ')',
                default => 'Option<' . self::rust($inner) . '>',
            };
        }
        return match ($shape) {
            'bool' => 'bool',
            'int' => 'i64',
            'float' => 'f64',
            'string' => 'String',
            'bytes', 'str' => 'PhpString',
            'map' => 'PhpArray',
            default => 'PhpValue',
        };
    }

    /** How the shape reads in PHPDoc terms. */
    public static function php(string|array $shape): string
    {
        if (is_array($shape)) {
            $inner = $shape[0] === 'tuple' ? null : ($shape[1] === 'never' ? 'mixed' : $shape[1]);
            return match ($shape[0]) {
                'list' => 'list<' . self::php($inner) . '>',
                'map', 'arr' => $inner === 'mixed' ? 'array' : 'array<' . self::php($inner) . '>',
                'tuple' => 'array{' . implode(', ', array_map(self::php(...), $shape[1])) . '}',
                default => '?' . self::php($inner),
            };
        }
        return match ($shape) {
            'string' => 'string (UTF-8)',
            'bytes', 'str' => 'string',
            'map' => 'array',
            'never' => 'mixed',
            default => $shape,
        };
    }
}
