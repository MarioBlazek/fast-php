# Phase 0: spike results

Three questions had to be answered before building fast-php on a native PHP extension instead of fast-anything's FFI + JSON bridge.

1. Can a Rust extension take and return **exact** PHP values?
2. Can a Rust extension **profile** PHP through the observer API, without editing the source?
3. What does a call cost, and when does porting pay off?

All three answers are yes, with one surprise about array conversions. Reproduce everything with:

```sh
sh spike/run.sh
```

Measured on an Apple Silicon Mac with PHP 8.5.8 NTS (Homebrew), Rust 1.98.1 and ext-php-rs 0.16.5. Every timed run uses `php -n`, which loads no php.ini (see "Xdebug" below).

## 1. Exact values

[`spike/roundtrip.php`](../spike/roundtrip.php) sends 28 values PHP → Rust → PHP and requires `===` on the way back. It checks key order and key types too, and keeps the sign of `-0.0`.

| | through the extension | through JSON (`json_encode` → `json_decode(…, true)`) |
|---|---|---|
| null, bools, ints incl. `PHP_INT_MIN`/`MAX` | ok | ok |
| `1.5`, `3.0`, `-0.0`, `5e-324` | ok | ok |
| `INF`, `-INF` | ok | **lost** |
| UTF-8, `"a\0b"` | ok | ok |
| binary string `"\xff\x00\xfe"` | ok | **lost** |
| lists, gaps, reversed/negative int keys, string keys, `"01"`/`"1.5"`/`" 1"` keys, mixed keys | ok | ok |
| binary key `["\xff" => 1]` | ok | **lost** |
| nested arrays, `[1, 1.0, '1']` | ok | ok |
| arrays with `unset()` holes | ok | — |

Other checks:
- Ports throw **PHP's own exception classes**: `ValueError`, `DivisionByZeroError` and `InvalidArgumentException` all arrive as themselves. An unknown class becomes `Exception` with the class name in the message.
- Objects are refused with a `TypeError` instead of being mangled.

Caveat on the JSON column: PHP → JSON → PHP keeps more than expected, because `json_decode(…, true)` turns `{"0": …}` back into int keys. The bigger problems are on the **Rust side** of fast-anything's bridge:
- A list with a gap reaches the port as a JSON *object*.
- `same()` in fast-anything's verify accepts `3` for `3.0` (`values_compare_like_the_languages_do` asserts this).

fast-php's verify will compare in PHP with `===`.

## 2. Profiling through the observer API

No C fallback is needed. ext-php-rs has the Zend observer API behind its `observer` feature, so [`rust/fastphp-runtime`](../rust/fastphp-runtime/src/lib.rs) is a ~250-line Rust extension.
- **Off by default.** It observes nothing unless `FASTPHP_PROF_DIR` is set, because `should_observe` is cached per function by PHP.
- **What it records per user function:** calls, own time, and cumulative time, where recursion counts once.
- **Builtins on request.** With `FASTPHP_PROF_INTERNAL=1` it also times `preg_replace`, `trim` and other builtins.
- **Web-ready.** Stats build up per process and are written at the end of every request to `prof-<pid>.json`. That covers `php -S` and FPM workers, which serve many requests.
- **Safe without opcache.** The cache of function pointers is cleared after each request, because without opcache a function's memory can hold a different function in the next request.

On [`examples/fuzzy-dedupe-cli`](../examples/fuzzy-dedupe-cli), best of 3:

| run | time |
|---|---:|
| plain `php -n app.php` | 0.935 s |
| with the observer profiler | 0.973 s (+4%) |
| with fast-anything's profiler (a line inserted in every function) | 0.992 s (+6%) |
| plain, but with the php.ini that loads Xdebug | **3.051 s** |
| plain, JIT on (`opcache.jit=tracing`) | 0.593 s |

On this app the overhead is about the same as fast-anything's. The observer wins on other grounds:
- it doesn't edit the source;
- it can see builtins;
- it works across web requests.

A `php -S` run with 6 requests was profiled correctly, including the builtin functions.

**Xdebug.** The php.ini on the test machine loads Xdebug, which makes the app **3.3× slower**. Any benchmark run with it is meaningless, and fast-anything's published PHP numbers may include such effects. fast-php's `doctor` will warn about it, and every timed run uses `php -n` plus the extensions it needs.

**JIT.** The JIT alone makes this app 1.6× faster. fast-php ranks against JIT-on PHP.

## 3. Crossing cost

[`spike/crossing.php`](../spike/crossing.php) calls the same functions four ways:
- **PHP:** the original PHP function.
- **FFI + JSON:** fast-anything's bridge, exactly as it generates it.
- **ext (generic):** an ext-php-rs function using ext-php-rs's own conversions.
- **ext (fast-php glue):** the same function reading zvals through `fastphp_value::FromPhp`.

Each cell is the time per call (best of 5), followed by the speedup over PHP. All four must return identical results before anything is timed.

JIT off:

| call | PHP | FFI + JSON | ext (generic) | ext (fast-php glue) |
|---|---:|---:|---:|---:|
| noop() | 20 ns | 483 ns, 0.0× | 24 ns, 0.8× | 24 ns, 0.8× |
| sum(10 ints) | 58 ns | 803 ns, 0.1× | 123 ns, 0.5× | **39 ns, 1.5×** |
| sum(1,000 ints) | 3.14 µs | 16.72 µs, 0.2× | 7.73 µs, 0.4× | **609 ns, 5.2×** |
| levenshtein(2 names) | 5.77 µs | 812 ns, 7.1× | 199 ns, 29× | 205 ns, 28× |
| findDuplicates(600 names) | 1.02 s | 3.95 ms, 258× | 3.32 ms, 307× | 3.13 ms, 325× |

JIT on (tracing):

| call | PHP | FFI + JSON | ext (generic) | ext (fast-php glue) |
|---|---:|---:|---:|---:|
| noop() | 15 ns | 435 ns, 0.0× | 22 ns, 0.6× | 22 ns, 0.7× |
| sum(10 ints) | 31 ns | 741 ns, 0.0× | 123 ns, 0.3× | 39 ns, 0.8× |
| sum(1,000 ints) | 1.11 µs | 16.73 µs, 0.1× | 7.94 µs, 0.1× | **633 ns, 1.8×** |
| levenshtein(2 names) | 3.82 µs | 736 ns, 5.2× | 206 ns, 18.6× | 205 ns, 18.7× |
| findDuplicates(600 names) | 667 ms | 3.92 ms, 170× | 3.01 ms, 222× | 3.02 ms, 221× |

What this means:
- **A call into the extension costs about as much as a PHP call** (22–24 ns, vs 15–20 ns). FFI + JSON costs about 450 ns before any work is done, 20× more. Porting an *inner* function pays off with the extension and not with FFI: levenshtein gains 18.7× instead of 5.2×.
- **ext-php-rs's generic array conversions are too slow for fast-php.** They cost about 7.5 ns per element, because every element gets an `ArrayKey`, often a heap `String`. `sum(1,000 ints)` came out slower than PHP. Reading the `zend_array` storage directly costs 0.6 ns per element: packed arrays are a zval vector, hash arrays a `Bucket` vector. That is 12× faster, and beats PHP even with the JIT on. Generated glue therefore takes `&Zval` and reads it with `FromPhp`; see [`rust/fastphp-value/src/raw.rs`](../rust/fastphp-value/src/raw.rs).
- **For big outer loops the bridge doesn't matter.** findDuplicates is 220–325× faster either way, because the time goes into the work. That is why fast-anything does well on its own benchmarks. The extension widens what is worth porting rather than making those cases faster.
- **ext-php-rs snake_cases function names** (`findDuplicates` → `find_duplicates`). Generated glue sets `#[php(name = "...")]`.

## Decisions for Phase 1

- Ports bridge through ext-php-rs, and glue reads arguments with `fastphp_value::FromPhp` and writes results with `IntoPhp`/`Ret`. No JSON anywhere.
- `fastphp-runtime` (observer profiler) is the default profiler. Source instrumentation stays as a fallback only.
- Every timed run is `php -n` plus the needed extensions, and `doctor` flags Xdebug, pcov and other profilers.
- Baselines are measured with the JIT off and on, and ranking uses JIT-on.
- Still to cover: ZTS builds, Linux in CI (PHP 8.2–8.5), and a whole-app benchmark through the enabled prologue (Phase 1).

## What's in the repo for this phase

| path | what |
|---|---|
| `rust/fastphp-value` | `PhpValue`/`PhpArray`/`PhpKey`/`PhpString`, fast `FromPhp`/`IntoPhp`, `PhpError` → PHP exception classes |
| `rust/fastphp-runtime` | observer profiler extension |
| `rust/spike-ext` | spike extension: round-trip, crossing-cost functions, fuzzy port |
| `rust/spike-ffi` | fast-anything's FFI + JSON bridge with the same functions, for comparison |
| `spike/roundtrip.php`, `spike/crossing.php`, `spike/run.sh` | the checks and benchmarks above |
| `examples/fuzzy-dedupe-cli` | fast-anything's PHP example (MIT), the first fixture |
