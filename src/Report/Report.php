<?php
declare(strict_types=1);

namespace FastPhp\Report;

/**
 * What fast-php found and did, from state.json: report.md for people and pull requests, and
 * report.svg, a chart of PHP's time against the ports' (per call and end to end).
 */
final class Report
{
    /** @param array<string, mixed> $state */
    public function __construct(private readonly array $state, private readonly string $workload)
    {
    }

    public function markdown(string $svgName = 'report.svg'): string
    {
        $s = $this->state;
        $scan = $s['scan'] ?? null;
        $md = "# fast-php report\n\n";
        $md .= "Workload: `{$this->workload}`";
        $md .= $scan ? " on PHP {$scan['php']}" : '';
        $md .= "\n\n";
        if ($this->bars()) {
            $md .= "![PHP against the Rust ports]($svgName)\n\n";
        }

        if ($scan) {
            $md .= "## Where the time goes\n\n";
            $md .= sprintf("JIT off %s, JIT on %s (%.1f×). Everything below compares against PHP with the JIT on.\n\n", self::ms($scan['jitOff']), self::ms($scan['jitOn']), $scan['jitOff'] / max($scan['jitOn'], 1e-12));
            $notes = '';
            foreach (['io' => 'waiting on the database, files or the network', 'autoload' => 'Composer autoloading'] as $k => $what) {
                if (($scan[$k] ?? 0) >= 0.1) {
                    $notes .= sprintf("- %s of the time is %s, which no port speeds up.\n", self::pct($scan[$k]), $what);
                }
            }
            $md .= ($notes !== '' ? $notes . "\n" : '') . "| function | share | calls | verdict |\n|---|---:|---:|---|\n";
            foreach (array_slice($scan['functions'], 0, 10) as $r) {
                $verdict = $r['verdict'] === 'blocked' ? 'blocked: ' . $r['blockers'][0] : $r['verdict'];
                $md .= sprintf("| `%s` | %s | %s | %s |\n", $r['name'], self::pct($r['share']), number_format($r['calls']), str_replace('|', '\|', $verdict));
            }
            $md .= "\n";
        }

        if ($s['ports'] ?? []) {
            $md .= "## Ports\n\n| port | status | recorded + derived calls | matched | per call: PHP → Rust |\n|---|---|---:|---:|---:|\n";
            foreach ($s['ports'] as $id => $p) {
                $v = $p['verify'] ?? null;
                $md .= sprintf("| `%s` | %s | %d + %d | %s | %s |\n", $id, $p['status'] . ($p['enabled'] ? ', enabled' : ''), $p['recorded'], $p['derived'],
                    $v ? "{$v['passed']} of " . ($v['total'] - $v['fellBack']) . ($v['fellBack'] ? " ({$v['fellBack']} left to PHP)" : '') : '-',
                    $v && $v['php'] && $v['native'] ? sprintf('%s → %s (%.1f×)', self::ms($v['php']), self::ms($v['native']), $v['php'] / $v['native']) : '-');
            }
            $md .= "\n";
        }

        if ($b = $s['bench'] ?? null) {
            $md .= "## End to end\n\n";
            $md .= sprintf("%s with the ports against %s without (%.1f×), JIT %s, %s.\n", self::ms($b['native']), self::ms($b['php']), $b['php'] / $b['native'], $b['jit'] ? 'on' : 'off',
                $b['identical'] ? 'identical output' : '**the output differed**');
            if (isset($b['phpLatency'])) {
                $md .= sprintf("\n| per request | p50 | p95 | requests/s |\n|---|---:|---:|---:|\n| PHP | %s | %s | %.0f |\n| with ports | %s | %s | %.0f |\n",
                    self::ms($b['phpLatency']['p50']), self::ms($b['phpLatency']['p95']), $b['phpLatency']['rps'],
                    self::ms($b['nativeLatency']['p50']), self::ms($b['nativeLatency']['p95']), $b['nativeLatency']['rps']);
            }
            $md .= "\n";
        }

        $md .= "## Turning it off\n\nThe ports run only where the extension is loaded; without it the original PHP runs. `FAST_PHP_DISABLE=1` switches them off, and `vendor/bin/fast-php disable --all` removes the prologues from the source byte for byte.\n";
        return $md;
    }

    /**
     * One row per port (per call) and one for the whole workload: PHP's time as a full bar, the
     * ports' time as a fraction of it.
     *
     * @return list<array{label: string, php: float, native: float}>
     */
    public function bars(): array
    {
        $rows = [];
        foreach ($this->state['ports'] ?? [] as $id => $p) {
            $v = $p['verify'] ?? null;
            if ($v && $v['php'] && $v['native'] && $p['status'] === 'verified') {
                $rows[] = ['label' => "$id, per call", 'php' => $v['php'], 'native' => $v['native']];
            }
        }
        if ($b = $this->state['bench'] ?? null) {
            $rows[] = ['label' => isset($b['phpLatency']) ? 'all requests' : 'whole run', 'php' => $b['php'], 'native' => $b['native']];
            if (isset($b['phpLatency'])) {
                $rows[] = ['label' => 'p95 per request', 'php' => $b['phpLatency']['p95'], 'native' => $b['nativeLatency']['p95']];
            }
        }
        return $rows;
    }

    public function svg(): string
    {
        $rows = $this->bars();
        $w = 720;
        $left = 270;
        $bar = $w - $left - 90;
        $h = 64 + count($rows) * 52 + 28;
        $out = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="$w" height="$h" viewBox="0 0 $w $h" font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif">
            <style>
              .bg { fill: #ffffff } .t { fill: #1f2328 } .m { fill: #59636e } .php { fill: #d0d7de } .rs { fill: #b7410e }
              @media (prefers-color-scheme: dark) { .bg { fill: #0d1117 } .t { fill: #e6edf3 } .m { fill: #9198a1 } .php { fill: #30363d } .rs { fill: #f0883e } }
            </style>
            <rect class="bg" width="100%" height="100%" rx="8"/>
            <text class="t" x="20" y="32" font-size="17" font-weight="600">PHP (JIT on) against the Rust ports</text>
            <rect class="php" x="20" y="44" width="12" height="10" rx="2"/><text class="m" x="38" y="53" font-size="12">PHP</text>
            <rect class="rs" x="80" y="44" width="12" height="10" rx="2"/><text class="m" x="98" y="53" font-size="12">with the ports</text>

            SVG;
        foreach ($rows as $i => $r) {
            $y = 74 + $i * 52;
            $frac = max(0.004, min(1.0, $r['native'] / max($r['php'], 1e-12)));
            $label = htmlspecialchars(strlen($r['label']) > 32 ? substr($r['label'], 0, 31) . '…' : $r['label'], ENT_XML1);
            $out .= sprintf('<text class="t" x="20" y="%d" font-size="13">%s</text>', $y + 15, $label) . "\n";
            $out .= sprintf('<rect class="php" x="%d" y="%d" width="%d" height="16" rx="3"/><text class="m" x="%d" y="%d" font-size="12">%s</text>', $left, $y, $bar, $left + $bar + 8, $y + 12, self::ms($r['php'])) . "\n";
            $out .= sprintf('<rect class="rs" x="%d" y="%d" width="%.1f" height="16" rx="3"/><text class="m" x="%.1f" y="%d" font-size="12">%s · %.1f×</text>', $left, $y + 20, $bar * $frac, $left + $bar * $frac + 8, $y + 32, self::ms($r['native']), $r['php'] / max($r['native'], 1e-12)) . "\n";
        }
        if (!$rows) {
            $out .= '<text class="m" x="20" y="90" font-size="13">No verified port or benchmark yet.</text>' . "\n";
        }
        return $out . "</svg>\n";
    }

    private static function ms(float $s): string
    {
        return match (true) {
            $s >= 1 => sprintf('%.2f s', $s),
            $s >= 1e-3 => sprintf('%.1f ms', $s * 1e3),
            $s >= 1e-6 => sprintf('%.1f µs', $s * 1e6),
            default => sprintf('%.0f ns', $s * 1e9),
        };
    }

    private static function pct(float $x): string
    {
        return sprintf('%.1f%%', $x * 100);
    }
}
