<?php
declare(strict_types=1);

namespace Fixture;

final class Text
{
    private static array $cache = [];

    public static function outer(array $xs): array
    {
        return array_map([self::class, 'inner'], $xs);
    }

    public static function inner(string $s): string
    {
        return strtolower(trim($s));
    }

    public static function viaString(array $xs): array
    {
        return array_map('Fixture\helper', $xs);
    }

    public static function cached(string $s): string
    {
        return self::$cache[$s] ??= strtoupper($s);
    }

    public static function callsCached(string $s): string
    {
        return self::cached($s) . '!';
    }

    public static function throws(int $n): int
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('negative');
        }
        return $n;
    }

    public static function makesObjects(): array
    {
        return [new \ArrayObject([])];
    }

    public function instance(int $n): int
    {
        return $n;
    }

    public static function byRef(array &$xs): int
    {
        return count($xs);
    }

    public static function random(int $n): int
    {
        return mt_rand(0, $n);
    }

    public static function prints(string $s): string
    {
        echo $s;
        return $s;
    }

    public static function nothing(int $n): void
    {
    }
}

final class Pricing
{
    private array $cache = [];

    public function __construct(private readonly float $rate, private readonly int $digits = 2)
    {
    }

    /** Reads $this->rate and, through a helper, $this->digits. */
    public function total(array $prices): float
    {
        return $this->roundIt(array_sum($prices) * (1 + $this->rate));
    }

    private function roundIt(float $v): float
    {
        return round($v, $this->digits);
    }

    public function remembers(string $k): int
    {
        $this->cache[$k] = 1;
        return count($this->cache);
    }

    public function sortsInPlace(): array
    {
        sort($this->cache);
        return $this->cache;
    }

    public function passesThis(): string
    {
        return spl_object_hash($this) . serialize($this);
    }
}

function helper(string $s): string
{
    return $s . '-';
}
