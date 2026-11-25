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
        self::assertSame('Map<i64>', Types::rust(Types::infer([['a' => 1]])));
        self::assertSame('Map<i64>', Types::rust(Types::infer([[0 => 1, 2 => 3]])), 'a list with a gap is a map');
        self::assertSame('PhpArray', Types::rust(Types::infer([['a' => 1, 'b' => 'x']])));
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

    public function testPhpDocRefinedByRecordings(): void
    {
        $doc = \FastPhp\Analysis\DocTypes::read("/**\n * @param list<string> \$names\n * @param array<string, int> \$counts\n * @param array{int, int} \$pair\n * @param string[] \$tags\n * @return ?float\n */");
        $p = $doc['params'];
        self::assertSame('Vec<String>', Types::rust(Types::param('array', [['José', 'Zoë']], true, $p['names'])));
        self::assertSame('Vec<PhpString>', Types::rust(Types::param('array', [["\xff"]], true, $p['names'])));
        self::assertSame('Vec<PhpString>', Types::rust(Types::param('array', [], true, $p['names'])), 'nothing recorded: PHPDoc alone');
        self::assertSame('Map<i64>', Types::rust(Types::param('array', [[]], true, $p['counts'])), 'an empty array is still a map');
        self::assertSame('(i64, i64)', Types::rust(Types::param('array', [[1, 2]], true, $p['pair'])));
        self::assertSame('Vec<String>', Types::rust(Types::param('array', [['a', 'b']], true, $p['tags'])), 'T[] that was always a list');
        self::assertSame('Map<String>', Types::rust(Types::param('array', [['x' => 'a']], true, $p['tags'])));
        self::assertSame('Vec<i64>', Types::rust(Types::param('array', [[1, 2]], true, $p['names'])), 'recordings win when PHPDoc is wrong');
        self::assertSame('PhpArray', Types::rust(Types::param('array', [['a']], false, $p['names'])), 'no fallback: PHPDoc is not trusted');
        self::assertSame('Option<f64>', Types::rust(Types::merge($doc['return'], Types::infer([1.5]))));
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

    public function testPhpDocTuplesAreInferredPerPosition(): void
    {
        $doc = ['list', ['tuple', ['int', ['list', 'int']]]];
        $offers = [[[1990, [0, 2]], [500, [1]]], [[700, []]]];
        self::assertSame('Vec<(i64, Vec<i64>)>', Types::rust(Types::param('array', $offers, true, $doc)));
        self::assertSame('(i64, Vec<i64>)', Types::rust(Types::refine(['tuple', ['int', ['list', 'int']]], [[5, [1, 2]], [0, []]])));
        // Recordings that aren't tuples of that length win over the PHPDoc.
        self::assertSame('Vec<i64>', Types::rust(Types::refine(['tuple', ['int', 'int']], [[1, 2, 3]])));
    }
}
