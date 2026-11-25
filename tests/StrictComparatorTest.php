<?php
declare(strict_types=1);

namespace FastPhp\Tests;

use FastPhp\Verify\StrictComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StrictComparatorTest extends TestCase
{
    /** @return iterable<string, array{mixed, mixed}> */
    public static function same(): iterable
    {
        yield 'ints' => [3, 3];
        yield 'nested lists' => [[[0, 1], [2, 3]], [[0, 1], [2, 3]]];
        yield 'maps' => [['a' => 1, 5 => 'x'], ['a' => 1, 5 => 'x']];
        yield 'NAN equals NAN' => [NAN, NAN];
        yield 'INF' => [INF, INF];
        yield 'binary strings' => ["\xff\x00", "\xff\x00"];
        yield 'null' => [null, null];
    }

    /** @return iterable<string, array{mixed, mixed, string}> */
    public static function different(): iterable
    {
        // The cases a JSON comparison (fast-anything's verify) lets through.
        yield 'int vs float' => [3, 3.0, 'expected int 3, got float'];
        yield 'int key vs string key' => [[1 => 'a'], ['1x' => 'a']];
        yield 'key order' => [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1], 'different order'];
        yield 'list vs list with a gap' => [[0 => 'a', 1 => 'b'], [0 => 'a', 2 => 'b']];
        yield '-0.0 vs 0.0' => [0.0, -0.0];
        yield 'string vs int' => ['1', 1];
        yield 'extra element' => [[1], [1, 2], 'extra key'];
        yield 'missing element' => [[1, 2], [1], 'missing key'];
        yield 'nested difference' => [[[0, 1]], [[0, 2]], 'result[0][1]'];
        yield 'bytes' => ["\xff", "\xfe"];
        yield 'float digits' => [0.1 + 0.2, 0.3];
    }

    #[DataProvider('same')]
    public function testSame(mixed $a, mixed $b): void
    {
        self::assertNull(StrictComparator::diff($a, $b));
    }

    #[DataProvider('different')]
    public function testDifferent(mixed $a, mixed $b, string $mentions = ''): void
    {
        $d = StrictComparator::diff($a, $b);
        self::assertNotNull($d);
        self::assertStringContainsString($mentions, $d);
    }
}
