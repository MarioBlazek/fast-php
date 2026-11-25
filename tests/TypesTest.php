<?php
declare(strict_types=1);

namespace FastPhp\Tests;

use FastPhp\Port\Types;
use PHPUnit\Framework\TestCase;

final class TypesTest extends TestCase
{
    public function testInfersNestedLists(): void
    {
        $shape = Types::infer([[[0, 1], [2, 3]], [], [[4, 5]]]);
        self::assertSame(['list', ['list', 'int']], $shape);
        self::assertSame('Vec<Vec<i64>>', Types::rust($shape));
    }

    public function testStringsAreUtf8OnlyWhenEveryRecordingIs(): void
    {
        self::assertSame('String', Types::rust(Types::infer(['José', 'Zoë'])));
        self::assertSame('PhpString', Types::rust(Types::infer(['José', "\xff"])));
    }

    public function testMapsAndMixed(): void
    {
        self::assertSame('PhpArray', Types::rust(Types::infer([['a' => 1]])));
        self::assertSame('PhpArray', Types::rust(Types::infer([[0 => 1, 2 => 3]])), 'a list with a gap is a map');
        self::assertSame('PhpValue', Types::rust(Types::infer([1, 'a'])));
        self::assertSame('Vec<f64>', Types::rust(Types::infer([[1.5, 2.0]])));
        self::assertSame('Vec<PhpValue>', Types::rust(Types::infer([[1, 2.0]])), 'int and float never merge');
    }

    public function testNulls(): void
    {
        $shape = Types::infer([1, null, 2]);
        self::assertSame('Option<i64>', Types::rust($shape));
        self::assertTrue(Types::nullable($shape));
        self::assertFalse(Types::nullable(['list', 'int']));
    }

    public function testDeclaredScalarsWin(): void
    {
        self::assertSame('int', Types::param('int', [], true));
        self::assertSame(['?', 'float'], Types::param('?float', [1.5], true));
        self::assertSame('string', Types::param('string', ['a'], true));
        self::assertSame('bytes', Types::param('string', ['a'], false), 'without a fallback, any string must be accepted');
        self::assertSame(['list', 'string'], Types::param('array', [['a']], true));
        self::assertSame('map', Types::param('array', [['a']], false));
        self::assertSame('mixed', Types::param(null, [], true));
    }

    public function testDeclaredNullable(): void
    {
        self::assertTrue(Types::declaredNullable(null));
        self::assertTrue(Types::declaredNullable('?array'));
        self::assertTrue(Types::declaredNullable('int|null'));
        self::assertTrue(Types::declaredNullable('mixed'));
        self::assertFalse(Types::declaredNullable('array'));
        self::assertFalse(Types::declaredNullable('int|string'));
    }
}
