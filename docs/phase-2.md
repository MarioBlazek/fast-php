# Phase 2: correctness depth

Phase 1 could port a static function whose types came from its declaration and its recorded
calls. Phase 2 makes ports right in the places PHP differs from Rust, and widens what can be
ported:

- **`fastphp-std`**: PHP's builtins in Rust, matching PHP byte for byte, checked against the running PHP by differential fuzzing on every PHP version in CI.
- **PHPDoc types**: `list<string>`, `array<string, int>`, `array{int, int}`, `T[]` and `?T` give ports precise types, refined by what was recorded.
- **Instance methods** whose only use of `$this` is reading properties. The properties become extra arguments.
- **Exception and warning parity**: messages must match, and an input on which PHP warns must fall back to PHP.
- **A deriver that knows PHP's edge cases** and the declared parameter types.
- **Helpers found through Composer's autoload maps**, even when the profiled run never loaded them.
- **A second example**, [`examples/invoice-report-cli`](../examples/invoice-report-cli), that uses all of the above.

## fastphp-std

Ports used to call Rust's own `trim()`, `to_lowercase()`, `regex` and float formatting. Each of those differs from PHP's somewhere. `fastphp-std` has the PHP versions, written from php-src, not from the manual:

| module | what |
|---|---|
| `string` | `trim`/`ltrim`/`rtrim` (default set, `a..z` ranges), ASCII-only case functions, `ucwords`, `str_pad`, `substr`, `str_replace` (single, arrays, case-insensitive), `explode`, `implode`, `strpos`/`stripos`/`strrpos`, `substr_count`, `str_split`, `levenshtein`, `similar_text`, `number_format` |
| `conv` | float → string as `echo`, `var_export` and `json_encode` print it (zend_gcvt), PHP 8 numeric strings, `(int)` and `(float)` casts |
| `math` | `+ - * /` with int overflow becoming a float, `%`, `intdiv`, `**` with Zend's mid-way switch to floats, `abs`, `round()` (both algorithms) |
| `cmp` | PHP 8 `<=>` and `==`, stable `sort`/`asort`/`ksort` |
| `mb` | `mb_strlen`, `mb_substr`, `mb_strtolower`/`upper`, `mb_str_split` |
| `pcre` | `preg_match`, `preg_match_all`, `preg_replace`, `preg_replace_callback`, `preg_split`, `preg_quote` on PCRE2, the engine PHP uses (built in statically) |
| `hash`, `array` | `crc32`, `md5`, `sha1`; `range`, `array_sum`, `array_unique`, `array_slice` |

Where PHP only warns and carries on, a function returns `Err(PhpError::fallback())`. The glue then lets PHP run the call, warning and all. Behaviour that changed between versions follows the PHP the extension was built for:
- `round()` was rewritten in 8.4;
- `number_format()` gained an int path in 8.3 and a large-float path in 8.4;
- several messages changed from "cannot be empty" to "must not be empty" in 8.4;
- `range()` errors changed in 8.3;
- `mb_strtolower` handles final sigma from 8.3 on.

Where Rust can't match PHP exactly, the call is declined:
- a sort whose PHP order depends on the algorithm: PHP's comparison isn't transitive across types (`"10" < "9a" < "9" < "10"`);
- invalid UTF-8 under `/u`;
- the `D` and `r` modifiers;
- `0 ** -1`.

**Differential fuzzing.** [`tests/std/fuzz.php`](../tests/std/fuzz.php) runs about 70 checks: each builtin against its Rust version, through a small check extension ([`rust/std-check`](../rust/std-check/src/lib.rs)). Inputs are generated from the bytes and values the two languages disagree about:
- whitespace PHP and Rust treat differently, `\0`, `ß`, `Σ`, `İ` and invalid UTF-8;
- numeric strings such as `"1e3"`, `" 12"`, `"9223372036854775808"`;
- `-0.0`, `INF`, `NAN`, 2^52, and decimals ending in 5;
- int limits, empty-matching patterns, replacement references.

A check fails when results differ, when an exception differs in class or message, or when PHP warned and Rust didn't decline. `sh tests/std/run.sh` runs three fixed seeds and one random seed; CI runs it on PHP 8.2–8.5.

What the fuzzer found while this was written, each fixed:
- **Messages:** "cannot be empty" became "must not be empty" in PHP 8.4, and `str_pad()`'s "must be a non-empty string" too; `range()`'s step error was reworded in 8.3.
- **`number_format(INF)`:** returns PHP's `printf` spelling, `inf`, lower case and without the sign.
- **`(float) "-0"`:** is `-0.0`, because the cast goes through `strtod`, not the numeric-string parser.
- **`round(4503599627370496.0)`:** gives `4503599627370497.0` in PHP 8.5.8. php-src fixed this on the 8.5 branch after 8.5.8 was released, so the answer depends on the patch release. `round()` now declines from 2^52 up, where patch releases differ.
- **`0 ** -1`:** is deprecated from 8.4 on, so it is declined.
- **`true == NAN`:** is `true`, because the comparison is between bools.

**Speed.** Per operation, on 200,000 order lines:

| | PHP (JIT on) | fastphp-std |
|---|---:|---:|
| `preg_replace('/[^a-z0-9]+/', '-', $s)` | 37.5 ms | 31 ms |
| `preg_match('/[^\x00-\x7F]/', $s)` | 7.4 ms | 5.4 ms |
| `strtolower(trim($s))` | 5.4 ms | 3.3 ms |
| `explode(';', $s)` | 10.9 ms | 12.8 ms |

Getting there took three changes:
- reusing PCRE2 match data instead of allocating it per match, as PHP does;
- a most-recently-used cache in front of the compiled-pattern map;
- `memchr` for substring search.

The numbers also show the limit of porting: **a builtin is already C.** A function that mostly calls builtins gains little. So `scan` now runs a second profile with builtins observed, and reports how much of a candidate's time is inside them.

## PHPDoc types

[`src/Analysis/DocTypes.php`](../src/Analysis/DocTypes.php) reads `@param` and `@return` (also the `@phpstan-` and `@psalm-` forms) with phpstan/phpdoc-parser:

| PHPDoc | Rust |
|---|---|
| `list<string>` | `Vec<String>`, or `Vec<PhpString>` if any recorded string wasn't UTF-8 |
| `array<string, int>` | `Map<i64>` (`IndexMap<PhpKey, i64>`: keys and their order as PHP had them) |
| `string[]`, `array<T>` | `Vec<T>` if every recorded value was a list, else `Map<T>` |
| `array{int, int}` | `(i64, i64)` |
| `?float`, `float\|null` | `Option<f64>` |

PHPDoc gives the intent, so `array<string, int>` stays a map even when every recorded value was empty. The recordings settle what PHPDoc leaves open. When the two contradict, the recordings win, or the port would decline every real call. PHP doesn't enforce PHPDoc, so it is only used when the glue has a fallback.

Types now come from the **real** recorded calls only. Derived edge cases, like a list with a gap or invalid UTF-8, exist to exercise the fallback; they must not widen the types.

## Instance methods

A method qualifies when its only uses of `$this` are:
- property reads (`$this->rate`);
- calls to methods of the same class (`$this->roundIt()`) that qualify themselves.

Writes block a port: assignments, `++`, `unset`, `foreach` by reference, and `sort($this->items)` or any other by-reference builtin. So does `$this` used as a value, a property name chosen at runtime, and an undeclared property on a class with `__get`.

Each property the method reads, directly or through its helpers, becomes an extra argument after the method's own. The prologue passes `$this->rate`, so the port sees exactly what the method would have read. The recorder reads the properties when the call starts. Derived cases re-read them from the live object, so recorded inputs and answers stay consistent.

Verify still times the original for instance methods. It builds one object per case without the constructor, sets the recorded properties from inside the class (readonly ones too), and does all of that before timing starts. It also loads the project's Composer autoloader. Before timing, it now checks that the original, loaded on its own, reproduces the recorded answers. Without that check, the first version of the invoice benchmark timed a fatal "class not found" and reported PHP as 1,000× faster.

## Exceptions and warnings

- **Messages:** an exception must now match in class **and** message. `verify --loose-messages` relaxes that.
- **Warnings:**
  - The recorder notes every warning, notice and deprecation the original emits during a recorded call, and still passes it on to the app's own handler.
  - A port can't emit those, so on such an input it must decline. If it returns a value anyway, verify fails and says which warning PHP gave.
  - On the fuzzy example, the derived "list with a gap" makes PHP warn about undefined keys, and the glue's type check sends it back to PHP.

## The deriver

- **Type-aware:** it reads the function's declared parameter types and skips values PHP would reject before the function runs.
- **New edge values:**
  - ints: `PHP_INT_MAX`, `PHP_INT_MIN`;
  - floats: `-0.0`, `INF`, `NAN`, `1e300`, `0.1 + 0.2`;
  - strings: numeric strings (`"0"`, `"1e3"`, `" 12"`), invalid UTF-8, long strings;
  - arrays: a list with a gap, the same values with string keys, and the `\f`, `\0` and upper-case decorations from Phase 1.
- **Bounded:**
  - derived arrays are capped at a few hundred elements, since the recorded calls already cover the real sizes;
  - each function gets a 64 MB recording budget;
  - the recording run and the replay run without a memory limit.

  The first run on the invoice example ran out of memory deriving variants of a 200,000-line list.

## Helpers through Composer

When analysis meets a class that no scanned file defines, it looks the class up in `vendor/composer/autoload_classmap.php` and `autoload_psr4.php`, parses that file, and repeats. On the invoice example, `Slug::make` calls `Ascii::fold` only for names with accents. The profiled run never loads `Ascii`, yet the scan lists it as a helper, and its blockers count.

## Results

| example | per call (verify, JIT on) | whole script (bench) |
|---|---:|---:|
| fuzzy-dedupe-cli: `Fuzzy::findDuplicates` | 440 ms → 1.7 ms (261×) | 604 ms → 52 ms (11.5×) |
| invoice-report-cli: `Report::summary` (instance method) | 12.3 ms → 6.6 ms (1.9×) | 243 ms → 198 ms (1.2×) |

The invoice port is half builtins (`explode`, `preg_*`, `round`, `number_format`, `ksort`) and half PHP loops (a Luhn check per SKU, the totals). Only the loops get much faster. The port also pays for copying 200,000 strings in. The rest of the script is the data generator, which isn't ported.

Two ways to make that copy cheaper are in reach:
- a borrowed string type that reads a `zend_string` in place;
- porting `Generator::check`, which `scan` lists as the next candidate.

**Wrong ports rejected** (`tests/e2e.sh`):
- fuzzy: a port that measures edit distance in bytes, not characters, fails 4 of 13 calls;
- invoice: a port that rounds the Rust way, `(x * 100).round() / 100`, fails 2 of 6, starting at `47,307,068.46` against `47,307,068.56`.

## Tests

- **`vendor/bin/phpunit`:** 51 tests. New ones cover PHPDoc merging and instance-method analysis: property reads, helper properties, writes, by-reference builtins, and `$this` as a value.
- **`cargo test`:** `fastphp-std` unit tests.
- **`sh tests/std/run.sh`:** differential fuzzing against the running PHP.
- **`sh tests/e2e.sh`:** both examples, each with a wrong port that must fail verify.

## Not done yet

- **`fastphp-std` coverage:**
  - not yet: `sprintf`, `str_word_count`, `wordwrap`, `array_map`-style functions with callbacks, `json_encode`/`json_decode`, `DateTime`;
  - partial: `preg_*` lacks `PREG_OFFSET_CAPTURE` and `PREG_SET_ORDER`.
- **Zero-copy string arguments:** a borrowed type that reads a `zend_string` in place.
- **Web workloads (FPM, `php -S`):** Phase 3.
- **Coding-agent runs with retries:** Phase 4.
