<?php
declare(strict_types=1);

namespace FastPhp\Tests;

use FastPhp\Report\Report;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    private const STATE = [
        'scan' => ['php' => '8.5.0 NTS', 'jitOff' => 0.2, 'jitOn' => 0.1, 'io' => 0.3, 'autoload' => 0.0, 'functions' => [
            ['name' => 'Shop\Bundles::cheapest', 'share' => 0.7, 'calls' => 33, 'verdict' => 'port', 'blockers' => []],
            ['name' => 'Shop\App::cart', 'share' => 0.9, 'calls' => 34, 'verdict' => 'blocked', 'blockers' => ['returns void | nothing']],
        ]],
        'ports' => ['Shop_Bundles_cheapest' => ['status' => 'verified', 'enabled' => true, 'recorded' => 33, 'derived' => 14,
            'verify' => ['total' => 47, 'passed' => 42, 'fellBack' => 5, 'failed' => 0, 'php' => 0.0033, 'native' => 0.00033]]],
        'bench' => ['jit' => true, 'php' => 0.054, 'native' => 0.015, 'identical' => true, 'ports' => ['Shop_Bundles_cheapest'],
            'phpLatency' => ['p50' => 0.001, 'p95' => 0.011, 'rps' => 720.0], 'nativeLatency' => ['p50' => 0.0006, 'p95' => 0.0017, 'rps' => 2547.0]],
    ];

    public function testMarkdown(): void
    {
        $md = (new Report(self::STATE, 'requests.http against php -S -t public'))->markdown();
        self::assertStringContainsString('JIT off 200.0 ms, JIT on 100.0 ms (2.0×)', $md);
        self::assertStringContainsString('30.0% of the time is waiting on the database', $md);
        self::assertStringContainsString('| `Shop_Bundles_cheapest` | verified, enabled | 33 + 14 | 42 of 42 (5 left to PHP) | 3.3 ms → 330.0 µs (10.0×) |', $md);
        self::assertStringContainsString('blocked: returns void \| nothing', $md, 'pipes in a cell are escaped');
        self::assertStringContainsString('| with ports | 600.0 µs | 1.7 ms | 2547 |', $md);
        self::assertStringContainsString('![PHP against the Rust ports](report.svg)', $md);
    }

    public function testSvgHasABarPairPerRow(): void
    {
        $r = new Report(self::STATE, 'w');
        self::assertSame(['Shop_Bundles_cheapest, per call', 'all requests', 'p95 per request'], array_column($r->bars(), 'label'));
        $svg = $r->svg();
        self::assertSame(3, substr_count($svg, 'class="rs"') - 1, 'one Rust bar per row, plus the legend');
        self::assertStringContainsString('10.0×', $svg);
        self::assertNotFalse(simplexml_load_string($svg), 'well-formed XML');
    }
}
