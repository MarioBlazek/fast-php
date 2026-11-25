<?php
declare(strict_types=1);

namespace FastPhp\Port;

use FastPhp\Analysis\Codebase;
use FastPhp\Analysis\Func;
use FastPhp\Project;

/**
 * TASK.md: everything a coding agent (or a person) needs to write port.rs. The structure
 * follows fast-anything's task_md (MIT), with PHP's own semantics spelled out, because that is
 * where ports go wrong.
 */
final class Task
{
    /** What a port must get exactly right about the builtins the original calls. */
    private const BUILTINS = [
        'strtolower' => 'ASCII only since PHP 8.2: bytes ≥ 0x80 are left alone (`Ł` stays `Ł`). Not `str::to_lowercase()`; use `to_ascii_lowercase()`.',
        'strtoupper' => 'ASCII only since PHP 8.2. Use `to_ascii_uppercase()`.',
        'ucfirst' => 'ASCII only: upper-cases the first byte if it is a-z.',
        'lcfirst' => 'ASCII only.',
        'ucwords' => 'ASCII only; words are split at " \t\r\n\f\v" by default.',
        'trim' => 'Default characters are " \t\n\r\0\x0B" (space, tab, LF, CR, NUL, vertical tab) — not \f, not Unicode spaces. `str::trim()` is different.',
        'ltrim' => 'Same default characters as trim().',
        'rtrim' => 'Same default characters as trim().',
        'preg_split' => 'PCRE2. Without /u, patterns see bytes and `\s` is ASCII whitespace (space, \t, \n, \v, \f, \r). With /u, `//u` splits into UTF-8 characters, and invalid UTF-8 makes it return false.',
        'preg_match' => 'PCRE2, not Rust\'s regex crate: backreferences, lookaround and `\s`/`\w` (ASCII without /u) differ. Returns 1/0, or false on error.',
        'preg_replace' => 'PCRE2. Replacement `$1`/`\\1` references; returns null on error.',
        'preg_replace_callback' => 'PCRE2; the callback gets the full match at index 0.',
        'preg_match_all' => 'PCRE2; default order is PREG_PATTERN_ORDER.',
        'strlen' => 'Bytes, not characters.',
        'substr' => 'Bytes; negative offsets/lengths count from the end; returns "" (not false) when out of range in PHP 8.',
        'str_pad' => 'Bytes.',
        'strrev' => 'Reverses bytes (breaks UTF-8).',
        'str_split' => 'Bytes.',
        'mb_strlen' => 'Characters (UTF-8 by default).',
        'mb_substr' => 'Characters.',
        'mb_strtolower' => 'Full Unicode case mapping (like `str::to_lowercase()`, but check special cases such as final sigma).',
        'levenshtein' => 'The builtin counts bytes, not characters.',
        'similar_text' => 'Bytes.',
        'implode' => 'Joins values converted to strings: ints print in decimal, floats use precision 17 shortest form (1.0 prints as "1"), true as "1", false/null as "".',
        'explode' => 'A negative limit drops that many parts from the end; an empty separator throws ValueError.',
        'str_replace' => 'With arrays, replacements are applied one after another, left to right.',
        'round' => 'Half away from zero, and PHP pre-rounds so round(1.955, 2) is 1.96. `f64::round()` alone is not enough.',
        'number_format' => 'Rounds like round(); default separators "." and ",".',
        'intdiv' => 'Throws DivisionByZeroError for 0, ArithmeticError for PHP_INT_MIN / -1.',
        'range' => 'Inclusive at both ends; ints give ints, a float step gives floats.',
        'min' => 'PHP comparison rules (numeric strings compare as numbers). Returns the original value, int or float as given.',
        'max' => 'PHP comparison rules (numeric strings compare as numbers).',
        'count' => 'Number of elements; not recursive by default.',
        'array_map' => 'With one array it keeps the keys (string keys too); with several it returns a list.',
        'array_filter' => 'Keeps the keys: the result can have gaps (not a list).',
        'array_slice' => 'Renumbers int keys unless preserve_keys; string keys always survive.',
        'array_unique' => 'Compares values as strings by default and keeps the first key of each.',
        'array_merge' => 'Renumbers int keys, overwrites string keys.',
        'sort' => 'Stable since PHP 8. Default flags compare with PHP 8 rules: numeric strings as numbers ("10" > "9").',
        'usort' => 'Stable since PHP 8; the result is a list.',
        'asort' => 'Stable; keeps keys.',
        'ksort' => 'Compares keys with PHP 8 rules: int keys and numeric strings as numbers.',
        'json_encode' => 'Escapes "/" and non-ASCII (\\u00e9) by default; floats like 1.0 become 1.0 only with JSON_PRESERVE_ZERO_FRACTION.',
        'sprintf' => '%d truncates floats toward zero, %.2f rounds half up on the decimal string, %s of a float uses precision 14.',
        'intval' => 'Leading-numeric strings: "12abc" is 12, "abc" is 0, " 12" is 12; saturates on overflow.',
        'floatval' => 'Leading-numeric strings.',
        'str_contains' => 'Bytes; "" is always contained.',
        'str_starts_with' => 'Bytes.',
        'str_ends_with' => 'Bytes.',
        'crc32' => 'IEEE CRC-32 as a non-negative int.',
        'md5' => 'Lowercase hex string.',
        'sha1' => 'Lowercase hex string.',
    ];

    /**
     * @param list<array<string, mixed>> $params
     * @param list<array<string, mixed>> $cases
     */
    public static function render(Project $project, Codebase $cb, Func $f, array $params, string|array $retShape, string $ret, bool $fallback, array $cases, string $fn): string
    {
        $id = $f->id();
        $where = $project->rel($f->file) . ':' . $f->line;
        $helpers = $cb->reachable($f);
        $builtins = $f->builtins;
        foreach ($helpers as $h) {
            $builtins += $h->builtins;
        }
        ksort($builtins);

        $md = "# Port {$f->display()} to Rust\n\n";
        $md .= "Write `src/port.rs` so that `$fn` returns exactly what `{$f->display()}` ($where) returns, for every input, and is faster.\n\n";
        $md .= "Check it with:\n\n```sh\nvendor/bin/fast-php verify $id\n```\n\n";
        $md .= "It builds this crate, replays " . count($cases) . " recorded calls against it inside PHP and compares with `===` (ints are not floats, key order and key types count, strings are bytes). It also times both versions.\n\n";

        $md .= "## Signature\n\n| PHP | type the recordings showed | Rust |\n|---|---|---|\n";
        foreach ($params as $p) {
            $decl = trim(($p['type'] ?? '') . ' $' . $p['name'] . ($p['default'] !== null ? ' = ' . $p['default'] : ''));
            $md .= "| `$decl` | `" . Types::php($p['shape']) . "` | `{$p['rust']}: {$p['rustType']}` |\n";
        }
        $md .= '| returns `' . ($f->returnType ?? 'mixed') . '` | `' . Types::php($retShape) . "` | `Result<$ret, PhpError>` |\n\n";
        $md .= "Types live in `fastphp_value`: `PhpString` (bytes), `PhpArray` (ordered map with int and string keys), `PhpValue` (any value). The glue in `src/glue.rs` is generated: don't edit it.\n\n";
        if ($fallback) {
            $md .= "If a call brings a value outside these types (say, a list with a gap where `Vec` is expected), the glue never calls your function and the original PHP runs instead. Your function may also decline an input on purpose with `Err(PhpError::fallback())`, for a rare case that isn't worth porting.\n\n";
        } else {
            $md .= "The original can return null, so there is no fallback to PHP: the port must handle every input the declared types allow.\n\n";
        }
        $md .= "Throw a PHP exception with `Err(PhpError::new(\"ValueError\", \"message\"))`: it arrives in PHP as that class.\n\n";

        $md .= "## The original\n\n```php\n" . $cb->source($f) . "\n```\n\n";
        if ($helpers) {
            $md .= "It calls these, which the port has to include too:\n\n";
            foreach ($helpers as $h) {
                $md .= "```php\n// " . $project->rel($h->file) . ':' . $h->line . "\n" . $cb->source($h) . "\n```\n\n";
            }
        }

        $md .= "## PHP semantics to match\n\n";
        $md .= "- Strings are bytes. Only `mb_*` functions and `/u` patterns see characters.\n";
        $md .= "- `+`, `-` and `*` on ints overflow into floats instead of wrapping. Use checked arithmetic and return the float, or decline.\n";
        $md .= "- `/` gives an int when the division is exact and a float otherwise; `%` works on ints and throws DivisionByZeroError for 0.\n";
        $md .= "- Arrays are ordered maps. A result that is a list in PHP must be a list (keys 0..n) here, and vice versa.\n";
        $md .= "- Don't panic (no `unwrap()` on input-dependent values): the glue turns a panic into a PHP `Error`, which fails verification.\n";
        $md .= "- Threads are fine (std::thread::scope) when the work is big enough to pay for them.\n\n";
        if ($builtins) {
            $md .= "Builtins the original calls:\n\n";
            foreach (array_keys($builtins) as $b) {
                $md .= "- `$b()`" . (isset(self::BUILTINS[$b]) ? ': ' . self::BUILTINS[$b] : '') . "\n";
            }
            $md .= "\n";
        }

        $md .= "## Sample calls\n\n";
        $shown = 0;
        foreach ($cases as $c) {
            if ($shown >= 6) {
                break;
            }
            if ($shown >= 3 && !$c['derived']) {
                continue; // after three recorded calls, show the derived edge cases
            }
            $args = implode(', ', array_map(static fn ($a) => Cases::show($a, 200), $c['args']));
            $out = isset($c['exc']) ? "throws {$c['exc'][0]}: {$c['exc'][1]}" : Cases::show($c['ret'], 200);
            $md .= "- `" . str_replace('`', "'", "{$f->display()}($args)") . "`" . ($c['derived'] ? ' (derived)' : '') . "\n  → `" . str_replace('`', "'", $out) . "`\n";
            $shown++;
        }
        $md .= "\nAll cases are in `cases.jsonl` (each line has the `serialize()`d arguments and result, base64-encoded).\n";
        return $md;
    }
}
