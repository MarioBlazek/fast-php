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
 * A shape is 'never' | 'null' | 'bool' | 'int' | 'float' | 'string' | 'bytes' | 'map' | 'mixed'
 * | ['list', shape] | ['?', shape]. 'string' is valid UTF-8, 'bytes' any string.
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
            is_array($v) => array_is_list($v)
                ? ['list', array_reduce($v, static fn ($s, $x) => self::join($s, self::shapeOf($x)), 'never')]
                : 'map',
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
        if (is_array($a) && is_array($b) && $a[0] === 'list' && $b[0] === 'list') {
            return ['list', self::join($a[1], $b[1])];
        }
        if (in_array($a, ['map', 'mixed'], true) && in_array($b, ['map', 'mixed'], true)) {
            return 'mixed';
        }
        // A list and a map are both arrays.
        if ((is_array($a) && $a[0] === 'list' && $b === 'map') || ($a === 'map' && is_array($b) && $b[0] === 'list')) {
            return 'map';
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

    /**
     * The shape of a parameter: the declared type where it is exact, else what was recorded.
     *
     * @param list<mixed> $samples
     */
    public static function param(?string $declared, array $samples, bool $fallback): string|array
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
        $shape = self::infer($samples);
        return $shape === 'never' ? 'mixed' : $shape;
    }

    public static function rust(string|array $shape): string
    {
        if (is_array($shape)) {
            return $shape[0] === 'list' ? 'Vec<' . self::rust($shape[1] === 'never' ? 'mixed' : $shape[1]) . '>' : 'Option<' . self::rust($shape[1]) . '>';
        }
        return match ($shape) {
            'bool' => 'bool',
            'int' => 'i64',
            'float' => 'f64',
            'string' => 'String',
            'bytes' => 'PhpString',
            'map' => 'PhpArray',
            default => 'PhpValue',
        };
    }

    /** How the shape reads in PHPDoc terms. */
    public static function php(string|array $shape): string
    {
        if (is_array($shape)) {
            return $shape[0] === 'list' ? 'list<' . self::php($shape[1] === 'never' ? 'mixed' : $shape[1]) . '>' : '?' . self::php($shape[1]);
        }
        return match ($shape) {
            'string' => 'string (UTF-8)',
            'bytes' => 'string',
            'map' => 'array',
            'never' => 'mixed',
            default => $shape,
        };
    }
}
