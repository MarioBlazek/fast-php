# fast-php: plan

> Phases 0–2 are done; their results are in [phase-0.md](phase-0.md), [phase-1.md](phase-1.md)
> and [phase-2.md](phase-2.md), and changed a few details below (marked **[phase N]**).

## Context

[fast-anything](https://github.com/EL4CTEO/fast-anything) (Rust CLI, MIT) profiles an app, ranks functions by `time share × portability`, records up to 500 real calls of the hot one, has a coding agent port **only that function** (preferring the outermost portable loop) to Rust, replays the recordings against the port, and swaps it in with a marked, reversible prologue. PHP is one of 12 backends there, and it is the thinnest one. We want a PHP-first tool that keeps the idea but fixes what is weak for PHP.

### What upstream does for PHP (src/php.rs + src/php/*.php)
- **Scan.** `describe.php` walks `token_get_all` output. It finds top-level functions and **static** methods only, and blocks instance methods, by-ref params, variadics, globals, static vars, generators, I/O and non-deterministic calls. Helpers are detected **in the same file only**.
- **Profile.** It inserts `$__faP = new \FastAnythingProf(i);` after every function's `{` and times the function with `hrtime` in the destructor, under `-d auto_prepend_file`. That adds an object alloc + destructor to every call, which inflates tiny hot functions. Builtins (`preg_*`, `array_*`) are invisible.
- **Record.** A prologue line calls `FastAnythingRec::record(...)`, which writes **JSON**. It rejects binary strings, objects, NaN/INF, and derives shrunk inputs.
- **Bridge.** `FFI::cdef` loads a cdylib with `fa_call(json)->json`. Every call pays for Reflection defaults, then `json_encode`, the FFI call, and `json_decode`.
- **Verify.** It replays inside the Rust binary (libloading). The comparator treats `3 == 3.0` as equal and floats within 1e-9.

### Why that falls short for PHP (this is the case for building fast-php)
1. **The FFI bridge does nothing in production web.** Under FPM, `ffi.enable=preload` is the default, so `FFI::cdef` throws. `load()` catches it and silently runs PHP. The "55×" only exists under the CLI. FFI::cdef also re-parses the definitions on every request.
2. **JSON is lossy for PHP values.** It loses int vs string array keys (`[0=>'a',2=>'b']` becomes an object, `"1"` becomes key `1`), the int vs float distinction on the comparison side (`3 !== 3.0` in PHP), gaps in lists, binary strings and objects. Verify can pass on ports that are actually wrong.
3. **Per-call crossing cost is µs.** JSON plus FFI makes anything below roughly 10µs of work a loss, so only huge outer loops qualify.
4. **Coverage is narrow.** There are no instance methods, which is where most modern PHP code lives (services, value objects). Helper detection works within one file only, and PHPDoc types (`list<string>`, `array<string,int>`) are ignored.
5. **The profiler distorts results**, and the workloads are CLI only (`php app.php`).
6. **There is no JIT-aware baseline.** PHP 8's tracing JIT already speeds up numeric loops 2–4×. An honest tool compares against JIT-on PHP and tells you when JIT or opcache.preload is the real fix.
7. **PHP semantics are only covered by four prose rules.** Examples include byte strings, PHP 8.2+ ASCII-only `strtolower`, PHP's `trim` charset, PCRE2 vs Rust `regex`, `round()` half-away-from-zero, int→float overflow, `/` returning an int when the division is exact, and key coercion. Agents get these wrong, and the lossy verify doesn't always catch it.

### Decisions (confirmed)
- **Bridge:** native PHP extension built from Rust via **ext-php-rs**. zvals are converted directly with no JSON, it works in FPM/CLI, and a call costs about 100ns.
- **Tool:** written in **PHP and shipped via Composer** (`composer require --dev`, `vendor/bin/fast-php`). Rust is used only for ports and the runtime ext.
- **v1 workloads:** **CLI scripts/jobs** and **web requests (FPM/Laravel/Symfony)**.

---

## Architecture

```
fast-php/                         (Composer package, PHP ≥ 8.2 for the tool; apps 8.2–8.5)
  bin/fast-php
  src/
    Cli/        Symfony Console: doctor, scan, port, verify, enable, disable, bench, report, go
    Analysis/   nikic/php-parser + phpstan/phpdoc-parser
                FunctionCollector, PurityVisitor, CallGraph (cross-file via resolved names),
                TypeResolver (native types + PHPDoc → Rust types)
    Workload/   CliWorkload (php [opts] script), WebWorkload (php -S router + request replay)
    Profile/    ObserverProfile (reads fastphp-runtime output), InstrumentProfile (fallback)
    Record/     prologue recorder (runtime PHP file), shard merge, EdgeCaseDeriver
    Port/       CrateScaffolder, TypeMapper, TaskWriter, AgentRunner
    Verify/     Replayer (child php -d extension=…), StrictComparator
    Patch/      marked-block insert/strip (byte-for-byte), Patches backup/restore
    Bench/      CLI interleaved runs; web replay with p50/p95/RPS
    State/      .fast-php/state.json
  rust/
    fastphp-value/    PhpValue / PhpKey / ordered map + FromZval/IntoZval (ext-php-rs)
    fastphp-std/      faithful PHP builtins in Rust (strings, pcre2, math, arrays, mb_*)
    fastphp-runtime/  profiler ext (zend_observer fcall begin/end), loaded with -d extension=
    templates/port/   per-port crate (port.rs that the agent edits + generated glue)
    templates/ext/    project aggregator crate → one fastphp_app.so with all verified ports
  examples/  fuzzy-dedupe-cli (upstream parity), web-app (slugify/pricing/markdown-ish hot path)
```

Project working dir: `.fast-php/` holds `state.json`, `ports/<id>/` (one crate each), `ext/` (cargo workspace that depends on the enabled ports), and `build/fastphp_app.so`.

### Pipeline, made PHP-specific

1. **doctor.** Checks PHP version, NTS/ZTS, `php-config` + headers, libclang (ext-php-rs bindgen needs it), rustup, opcache/JIT state, and agents.
2. **scan -- <cmd>** or **scan --web <requests-file>**
   - Profile with `-d extension=fastphp-runtime.so`. Observer hooks record calls, inclusive/exclusive time (hrtime), and internal-function time attributed to the user caller. Nothing in the source is touched.
   - The fallback is upstream-style instrumentation with probe-cost calibration (measure an empty probe and subtract it per call).
   - Run the workload twice, **JIT off vs `opcache.jit=tracing`**. Rank against the JIT-on baseline. If JIT alone gets most of the gain, say so. For web, report the framework bootstrap share and point at opcache.preload or Octane, because that cost isn't portable.
   - Analysis uses php-parser. It covers functions, static methods, **and instance methods whose only `$this` use is reading readonly/immutable props** (props get passed in as an extra arg). Blockers stay as upstream, plus `$$var`, `extract`, `compact`, dynamic calls, `callable`/Closure params, and superglobals.
   - Helpers are resolved across files through the namespace/use resolution and the Composer classmap.
   - Ranking reuses upstream's `rank()` logic (share × portability, outermost portable caller covers inner ones), ported to PHP.
3. **port [id]**
   - Insert a marked prologue that calls `\FastPhp\Rec::record()`. Under web, each worker writes its own shard (`rec-<pid>.jsonl`), and reservoir sampling merges them to N=500.
   - Each case is encoded with **`serialize()`** (base64 inside JSONL), which keeps the types, keys, binary strings and plain objects exact.
   - `EdgeCaseDeriver` adds shrunk slices plus PHP edge cases: `""`, `0`, `-0.0`, `PHP_INT_MAX`/overflow, NAN/INF, non-UTF-8 bytes, numeric strings (`"1e3"`, `" 1"`), and lists with gaps / string keys. **The original PHP answers them** in the same process, and its exception class and message are recorded too.
   - Scaffold the crate:
     - `src/port.rs` is a typed stub for the agent. The TypeMapper maps `int→i64`, `float→f64`, `string→PhpString (bytes)`, upgraded to `String` only if every recording is UTF-8 **and** the code uses `mb_`/`/u`; `list<T>→Vec<T>`; `array<K,V>→PhpMap`; `mixed→PhpValue`.
     - `src/glue.rs` is generated: an ext-php-rs `#[php_function]` named `fastphp\<Slug>`, zval↔typed conversions, defaults filled from the declaration, and `Err(PhpError{class,msg})` thrown as the same exception class.
     - `TASK.md` holds the original, helpers, sample calls, and **PHP semantics rules plus a pointer to `fastphp-std`**.
4. **verify** runs **inside PHP**: `php -n -d extension=<port.so> Replayer.php`.
   - It unserializes each case, calls the native function, and compares with `StrictComparator`: identical types (`3 !== 3.0`), key order and key types, and the same exception class. Float tolerance is **off** by default and an opt-in per port.
   - It then runs an in-process microbench: original PHP (JIT on) vs native per call, which includes the crossing cost.
   - A port is enabled only if all cases match **and** it is faster per call (upstream's rule).
5. **enable / disable**
   - Rebuild `.fast-php/ext` (all verified, enabled ports) → `fastphp_app.so`.
   - Insert the marked prologue, with **explicit args, not `func_get_args()`**:
     `if (\function_exists('fastphp\Fuzzy_findDuplicates') && !\FastPhp\off()) return \fastphp\Fuzzy_findDuplicates($names, $maxDistance);`
   - If the extension isn't loaded, PHP runs unchanged, so deploys are safe. `FAST_PHP_DISABLE=1` or the ini `fastphp.disable=1` forces PHP.
   - `fast-php install` prints or writes the `conf.d/fastphp.ini` line and reminds you to restart FPM.
   - Disable strips the block byte for byte (upstream `strip_in_body` semantics, plus a round-trip test).
6. **bench**
   - CLI: interleaved runs, original (disabled) vs native, both JIT-on.
   - Web: replay the request file against `php -S` (or `--url` for the user's FPM) and report p50/p95/RPS before and after.
7. **report** produces Markdown + SVG. **go --agent claude|codex|cmd** runs scan → port → agent → verify, feeding failures back up to K retries → enable → bench.

### fastphp-std (the main thing fast-php adds over upstream)
- Rust re-implementations of the PHP builtins ports actually need, matching PHP exactly: `trim`/`rtrim` (PHP charset), `strtolower`/`ucfirst` (ASCII, 8.2+), `explode`/`implode`, `str_pad`, `substr` (negative offsets), `str_replace`, `number_format`, `round` (pre-rounding behavior), `intdiv`/`%`/`/` result types, key normalization (`"1"→1`), stable `sort`/`usort` comparisons using PHP 8 compare rules, `levenshtein`, `similar_text`, `crc32`/`md5`/`sha1`, `mb_strlen`/`mb_substr`/`mb_strtolower`, and `preg_*` via the **`pcre2` crate** (same engine as PHP), not `regex`.
- Each function is checked by a **differential fuzz suite**: generate inputs, run PHP and Rust, compare with StrictComparator, in CI per PHP version.

---

## Phases

**Phase 0: spikes. Done, see [phase-0.md](phase-0.md).**
- ext-php-rs 0.16.5 builds and loads on PHP 8.5 (macOS); CI covers Linux 8.2–8.5. Values round-trip exactly.
- **[phase 0]** No C needed: ext-php-rs has the observer API (`observer` feature), and `fastphp-runtime` is Rust.
- **[phase 0]** Generated glue must not use ext-php-rs's generic array conversions (about 7.5 ns per element, which is
  slower than PHP). It reads zvals through `fastphp_value::FromPhp` (0.6 ns per element), and names functions explicitly with
  `#[php(name = "...")]` (ext-php-rs snake_cases names by default).
- **[phase 0]** `doctor` warns about Xdebug, and every timed run uses `php -n` plus the extensions it needs: Xdebug made
  the example app 3.3× slower.

**Phase 1: CLI MVP, end to end on the upstream PHP example. Done, see [phase-1.md](phase-1.md).**
- Analysis (php-parser), observer profile + fallback, JIT A/B, serialize recorder, scaffold, PHP-side verify, enable/disable, CLI bench, state, doctor.
- Port `examples/fuzzy-dedupe-php` plus its reference `port.rs` (adapted to the typed ext glue) as the CI fixture.
- **[phase 1]** Conversions in `fastphp-value` are exact (no int→float, `Vec` only from lists), and generated glue returns
  null for any argument outside its types, so the prologue runs the original PHP. That makes types inferred from the
  recordings safe. It needs a result that is never null; otherwise the glue has no fallback and uses declared types only.
- **[phase 1]** Cross-file helpers are already resolved (every file the profile touched is parsed); the edge-case
  deriver exists in a first form (sizes, shrunk elements, whitespace/case decorations, int neighbours).
- **[phase 1]** The source-instrumentation profiler fallback is not built: the observer profiler has worked everywhere so far.

**Phase 2: correctness depth. Done, see [phase-2.md](phase-2.md).**
- fastphp-std + differential fuzz, PHPDoc types, cross-file helpers, readonly instance methods, edge-case deriver, exception parity.
- **[phase 2]** fastphp-std follows php-src, not the manual, and version-gates what changed between 8.2 and 8.5. Where
  even patch releases differ (round() from 2^52 up) or PHP's result depends on its algorithm (sorting mixed types), it
  declines and PHP runs the call.
- **[phase 2]** Warnings are part of parity: an input on which the original warns must fall back to PHP.
- **[phase 2]** A port of builtin-heavy code gains little (the builtins are C already); scan measures and reports the
  builtin share of each candidate. Zero-copy string arguments are the next speed lever.

**Phase 3: web workloads. Done, see [phase-3.md](phase-3.md).**
- `php -S` launcher with a router (index.php), request file format (method/url/headers/body; HAR import), sharded recording, web bench (with `--url` for real FPM), `install` command, FPM restart notes.
- A small framework-free web example (hot slugify/pricing loop), plus one Laravel smoke example.
- **[phase 3]** The example's hot path is deal pricing (a bitmask DP), not slugify: slugify is builtins, and Phase 2
  showed those gain little.
- **[phase 3]** Web timings warm up twice and the profile subtracts the warm-up; a cold profile blamed Laravel's
  autoloader for half the time.
- **[phase 3]** FPM is benchmarked (`bench --url`, before/after) and installed into, but profiling and recording still
  run under `php -S`.

**Phase 4: agent loop and polish**
- `go --agent`, retry loop with verify feedback, report/SVG, Claude Code plugin + slash command (mirroring upstream `commands/fast.md` / `skills/`).

## What to reuse from upstream (MIT, credit in NOTICE)
- `src/hotspot.rs` `rank()` (outermost portable caller, the `WORTH_IT=0.02` threshold): port it to PHP.
- `src/native.rs` `insert_in_body` / `strip_in_body` / `Patches`, with leftover restore after a killed run: port to PHP.
- `src/native.rs` `task_md` structure and `sample_calls` formatting.
- `src/php/describe.php` blocker lists (IO, NONDET): seed the PurityVisitor.
- `src/php/rec.php` reservoir sampling + `variants()` shrinking: carry over, switching to serialize().
- `examples/fuzzy-dedupe-php/*` + `reference/port.rs`: the first fixture.

## Verification
- **Unit (PHPUnit):** analysis fixtures (blockers, helpers, types); patch insert/strip round-trip byte for byte (LF/CRLF, comments after `{`); StrictComparator (int/float, key types/order, gaps); rank().
- **Rust:** `cargo test` for fastphp-value conversions; differential fuzz for fastphp-std against `php -r`.
- **E2E, no agent:**
  - Copy the reference port into the crate, run `fast-php go -- php examples/fuzzy-dedupe-cli/app.php`, and expect verify 100% plus a speedup well beyond upstream's 55× (no JSON).
  - Output must be identical with `FAST_PHP_DISABLE=1`.
  - `disable` restores the source byte for byte.
- **Negative:** a deliberately wrong port (bytes vs chars, `3.0` returned for `3`, keys reordered) must fail verify. Upstream's JSON comparator would pass the int/float and key-type cases; ours must not.
- **Web E2E:** run `fast-php scan --web examples/web-app/requests.txt`, then port with the reference, enable, and bench against `php -S`. p95 must drop and responses must be byte-identical to the original.
- **CI matrix:** Ubuntu + macOS × PHP 8.2/8.3/8.4/8.5 × NTS (+ ZTS on one PHP version). Windows is deferred (ext-php-rs on Windows has its own friction).

## Risks and their answers
- **ext build friction** (libclang, php-dev headers): `doctor` checks up front and prints exact install commands per OS. The ext is built locally, so the ABI always matches the user's PHP.
- **The production server needs the ext installed:** the `function_exists` guard means a missing ext only costs speed, never correctness. `install` writes the ini.
- **Many PHP web apps are I/O-bound:** scan says so plainly (DB/HTTP share, bootstrap share) instead of porting something that doesn't matter.
- **Observer API from Rust:** Phase 0 spike, with the C fallback ready.
