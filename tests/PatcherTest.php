<?php
declare(strict_types=1);

namespace FastPhp\Tests;

use FastPhp\Patch\Patcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PatcherTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, string}> */
    public static function sources(): iterable
    {
        yield 'method, brace on next line' => ["<?php\nfinal class A\n{\n    public static function f(array \$a, int \$b = 2): array\n    {\n        return \$a;\n    }\n}\n", 'A', 'f'];
        yield 'function, brace on same line' => ["<?php\nfunction f(\$x) { return \$x; }\n", null, 'f'];
        yield 'CRLF line endings' => ["<?php\r\nclass A\r\n{\r\n    static function f(): int\r\n    {\r\n        return 1;\r\n    }\r\n}\r\n", 'A', 'f'];
        yield 'comment after the brace' => ["<?php\nfunction f(): int { // why\n    return 1;\n}\n", null, 'f'];
        yield 'defaults with parens and brackets' => ["<?php\nfunction f(array \$a = [1, 2], int \$b = (1 + 2), string \$c = PHP_EOL): string\n{\n    return '{';\n}\n", null, 'f'];
        yield 'attributes and a closure default-free body' => ["<?php\nclass A {\n    #[Pure]\n    public static function g(): array { return array_map(fn (\$x) => \$x, [1]); }\n    public static function f(): array { return []; }\n}\n", 'A', 'f'];
        yield 'namespaced function' => ["<?php\nnamespace App\\Text;\n\nfunction slug(string \$s): string\n{\n    return \$s;\n}\n", null, 'App\\Text\\slug'];
        yield 'namespaced class' => ["<?php\nnamespace App;\n\nfinal class Price\n{\n    public static function total(array \$items): int\n    {\n        return 0;\n    }\n}\n", 'App\\Price', 'total'];
    }

    #[DataProvider('sources')]
    public function testInsertThenStripGivesTheSameBytes(string $code, ?string $class, string $name): void
    {
        $patched = Patcher::insert($code, $class, $name, 'X_f', 'return 42;');
        self::assertNotSame($code, $patched);
        self::assertSame(substr_count($code, "\n"), substr_count($patched, "\n"), 'line numbers must not move');
        self::assertSame($code, Patcher::strip($patched, 'X_f'));
        self::assertSame($code, Patcher::stripAll($patched));
    }

    #[DataProvider('sources')]
    public function testThePatchRunsFirst(string $code, ?string $class, string $name): void
    {
        $patched = Patcher::insert($code, $class, $name, 'X_f', 'return 42;');
        $at = strpos($patched, Patcher::begin('X_f'));
        self::assertSame('{', substr($patched, $at - 1, 1));
    }

    public function testInsertingTwiceReplaces(): void
    {
        $code = "<?php\nfunction f() { return 1; }\n";
        $once = Patcher::insert($code, null, 'f', 'X', 'return 2;');
        self::assertSame($once, Patcher::insert($once, null, 'f', 'X', 'return 2;'));
    }

    public function testTwoPatchesStripIndependently(): void
    {
        $code = "<?php\nfunction f() { return 1; }\nfunction g() { return 2; }\n";
        $both = Patcher::insert(Patcher::insert($code, null, 'f', 'F', 'return 3;'), null, 'g', 'G', 'return 4;');
        self::assertSame(Patcher::insert($code, null, 'g', 'G', 'return 4;'), Patcher::strip($both, 'F'));
        self::assertSame($code, Patcher::stripAll($both));
    }

    public function testMissingFunctionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Patcher::insert("<?php\nfunction f() {}\n", null, 'nope', 'X', 'return;');
    }
}
