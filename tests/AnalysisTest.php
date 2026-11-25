<?php
declare(strict_types=1);

namespace FastPhp\Tests;

use FastPhp\Analysis\Codebase;
use FastPhp\Analysis\Ranker;
use PHPUnit\Framework\TestCase;

final class AnalysisTest extends TestCase
{
    private static Codebase $cb;

    public static function setUpBeforeClass(): void
    {
        self::$cb = Codebase::load([__DIR__ . '/fixtures/analysis.php']);
    }

    private static function blockers(?string $class, string $name): array
    {
        $f = self::$cb->find($class, $name);
        self::assertNotNull($f, "$class::$name");
        return Ranker::blockers(self::$cb, $f);
    }

    public function testPortableFunctions(): void
    {
        self::assertSame([], self::blockers('Fixture\Text', 'outer'));
        self::assertSame([], self::blockers('Fixture\Text', 'inner'));
        self::assertSame([], self::blockers('Fixture\Text', 'viaString'));
        self::assertSame([], self::blockers('Fixture\Text', 'throws'), 'throwing PHP exceptions is fine');
        self::assertSame([], self::blockers(null, 'Fixture\helper'));
    }

    public function testCallGraphFollowsCallableArraysAndStrings(): void
    {
        $outer = self::$cb->find('Fixture\Text', 'outer');
        self::assertArrayHasKey('fixture\text::inner', $outer->calls);
        $via = self::$cb->find('Fixture\Text', 'viaString');
        self::assertArrayHasKey('fixture\helper', $via->calls);
        self::assertSame(['strtolower' => true, 'trim' => true], self::$cb->find('Fixture\Text', 'inner')->builtins);
    }

    public function testBlockers(): void
    {
        $expect = [
            'cached' => 'static properties',
            'makesObjects' => 'creates objects',
            'instance' => 'instance method',
            'byRef' => 'by reference',
            'random' => 'mt_rand',
            'prints' => 'prints output',
            'nothing' => 'returns void',
        ];
        foreach ($expect as $name => $why) {
            $b = self::blockers('Fixture\Text', $name);
            self::assertNotEmpty($b, $name);
            self::assertStringContainsString($why, implode("\n", $b), $name);
        }
    }

    public function testHelpersPassTheirBlockersOn(): void
    {
        $b = self::blockers('Fixture\Text', 'callsCached');
        self::assertStringContainsString('calls Fixture\Text::cached, which uses static properties', implode("\n", $b));
    }

    public function testRankPicksTheOutermostPortableCaller(): void
    {
        $file = __DIR__ . '/fixtures/analysis.php';
        $p = static fn (string $name, float $cum, ?string $class = 'Fixture\Text') => ['name' => $name, 'class' => $class, 'file' => $file, 'line' => 1, 'internal' => false, 'calls' => 1, 'own' => $cum / 2, 'cum' => $cum];
        $rows = Ranker::rank(self::$cb, [$p('outer', 0.9), $p('inner', 0.8), $p('cached', 0.05), $p('Fixture\helper', 0.001, null)], 1.0, static fn ($f) => basename($f));
        $verdicts = array_column($rows, 'verdict', 'name');
        self::assertSame('port', $verdicts['Fixture\Text::outer']);
        self::assertSame('covered by Fixture_Text_outer', $verdicts['Fixture\Text::inner']);
        self::assertSame('blocked', $verdicts['Fixture\Text::cached']);
        self::assertSame('too small', $verdicts['Fixture\helper']);
    }

    public function testDeclarationsOnly(): void
    {
        self::assertTrue(self::$cb->declarationsOnly(__DIR__ . '/fixtures/analysis.php'));
        self::assertFalse(self::$cb->declarationsOnly(__DIR__ . '/../examples/fuzzy-dedupe-cli/app.php'));
    }
}
