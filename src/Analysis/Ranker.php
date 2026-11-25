<?php
declare(strict_types=1);

namespace FastPhp\Analysis;

/**
 * Which functions are worth porting, after fast-anything's rank() (MIT): a function qualifies
 * when it and every helper it calls can be ported and it takes at least WORTH_IT of the run.
 * Among those, the outermost wins: porting Fuzzy::findDuplicates covers the 179,700 calls to
 * Fuzzy::levenshtein inside it, and removes the crossings too.
 */
final class Ranker
{
    public const WORTH_IT = 0.02;

    /**
     * @param list<array{name: string, class: ?string, file: ?string, line: int, internal: bool, calls: int, own: float, cum: float}> $profile
     * @return list<array<string, mixed>> hottest first
     */
    public static function rank(Codebase $cb, array $profile, float $wall, callable $rel): array
    {
        $rows = [];
        foreach ($profile as $p) {
            if ($p['internal'] || $p['name'] === '{main}' || str_contains($p['name'], '{closure')) {
                continue;
            }
            $f = $cb->find($p['class'], $p['name']);
            if ($f === null) {
                continue;
            }
            $why = self::blockers($cb, $f);
            $rows[$f->keyOf()] = [
                'id' => $f->id(),
                'name' => $f->display(),
                'file' => $rel($f->file),
                'line' => $f->line,
                'calls' => $p['calls'],
                'own' => $p['own'],
                'cum' => $p['cum'],
                'share' => $wall > 0 ? $p['cum'] / $wall : 0.0,
                'portable' => !$why,
                'blockers' => $why,
                'helpers' => array_values(array_map(static fn (Func $h) => $h->display(), $cb->reachable($f))),
                'verdict' => '',
            ];
        }
        uasort($rows, static fn ($a, $b) => $b['cum'] <=> $a['cum']);

        $covered = [];
        foreach ($rows as $key => &$row) {
            if (isset($covered[$key])) {
                $row['verdict'] = 'covered by ' . $covered[$key];
            } elseif (!$row['portable']) {
                $row['verdict'] = 'blocked';
            } elseif ($row['share'] < self::WORTH_IT) {
                $row['verdict'] = 'too small';
            } else {
                $row['verdict'] = 'port';
                foreach ($cb->reachable($cb->funcs[$key]) as $k => $_) {
                    $covered[$k] ??= $row['id'];
                }
            }
        }
        return array_values($rows);
    }

    /** Own blockers plus those of every helper it calls. @return list<string> */
    public static function blockers(Codebase $cb, Func $f): array
    {
        $why = $f->blockers;
        foreach ($cb->reachable($f) as $h) {
            foreach ($h->blockers as $b) {
                $why[] = "calls {$h->display()}, which $b";
            }
        }
        return $why;
    }
}
