<?php
declare(strict_types=1);

namespace FastPhp\Port;

/**
 * Recorded calls of one function (.fast-php/ports/<id>/cases.jsonl): arguments, and the
 * result or the exception the original PHP gave. Values are serialize()d, so int vs float, key
 * types and order and binary strings are exact.
 */
final class Cases
{
    /**
     * @return list<array{derived: bool, args: list<mixed>, ret?: mixed, exc?: array{0: string, 1: string}}>
     */
    public static function read(string $file): array
    {
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $c = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $case = ['derived' => (bool) $c['derived'], 'args' => unserialize(base64_decode($c['args'])), 'warn' => $c['warn'] ?? []];
            if ($c['exc'] !== null) {
                $case['exc'] = $c['exc'];
            } else {
                $case['ret'] = unserialize(base64_decode($c['ret']));
            }
            $out[] = $case;
        }
        return $out;
    }

    /**
     * Merges the shards written by each PHP process, keeping at most $max recorded calls (plus
     * every derived one), and drops exact duplicates.
     *
     * @param list<string> $shards
     * @return array{recorded: int, derived: int}
     */
    public static function merge(array $shards, string $id, string $into, int $max): array
    {
        $recorded = [];
        $derived = [];
        $seen = [];
        foreach ($shards as $shard) {
            foreach (file($shard, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $c = json_decode($line, true);
                if (!is_array($c) || $c['id'] !== $id || isset($seen[$c['args']])) {
                    continue;
                }
                $seen[$c['args']] = true;
                $c['derived'] ? $derived[] = $line : $recorded[] = $line;
            }
        }
        if (count($recorded) > $max) {
            shuffle($recorded);
            $recorded = array_slice($recorded, 0, $max);
        }
        $lines = [...$recorded, ...$derived];
        file_put_contents($into, $lines ? implode("\n", $lines) . "\n" : '');
        return ['recorded' => count($recorded), 'derived' => count($derived)];
    }

    /** A short, readable PHP rendering of a value for TASK.md. */
    public static function show(mixed $v, int $limit = 300): string
    {
        $s = self::export($v, $limit);
        return strlen($s) > $limit ? substr($s, 0, $limit) . ' …' : $s;
    }

    private static function export(mixed $v, int $budget): string
    {
        if (is_array($v)) {
            $parts = [];
            $list = array_is_list($v);
            $used = 0;
            foreach ($v as $k => $x) {
                $p = ($list ? '' : var_export($k, true) . ' => ') . self::export($x, $budget);
                $parts[] = $p;
                $used += strlen($p) + 2;
                if ($used > $budget) {
                    $parts[] = '… ' . (count($v) - count($parts)) . ' more';
                    break;
                }
            }
            return '[' . implode(', ', $parts) . ']';
        }
        if (is_float($v)) {
            $s = var_export($v, true);
            return $s;
        }
        return var_export($v, true);
    }
}
