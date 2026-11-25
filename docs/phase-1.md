# Phase 1: the CLI pipeline, end to end

Phase 1 turns the Phase 0 spikes into a tool: `vendor/bin/fast-php`, a Composer package that
takes a PHP script from profile to an enabled Rust port. It runs on the fuzzy-dedupe example
with no coding agent, using a reference port written beforehand. CI runs the same steps on
PHP 8.2–8.5 (`sh tests/e2e.sh`).

```sh
vendor/bin/fast-php doctor
vendor/bin/fast-php scan -- php app.php
vendor/bin/fast-php port Fuzzy_findDuplicates      # then write port.rs from TASK.md
vendor/bin/fast-php verify Fuzzy_findDuplicates
vendor/bin/fast-php enable Fuzzy_findDuplicates
vendor/bin/fast-php bench
vendor/bin/fast-php disable Fuzzy_findDuplicates
```

## Results on examples/fuzzy-dedupe-cli

Apple Silicon, PHP 8.5.8 NTS. Every run uses `php -n`.

| step | result |
|---|---|
| scan | JIT off 934 ms, JIT on 605 ms. The JIT alone is 1.5×. |
| ranking | `Fuzzy::findDuplicates` 99.6% → **port**. `Fuzzy::levenshtein` (96.9%, 179,700 calls) and `normalize` → covered by it. `make_names` → blocked (`mt_srand`, `mt_rand`). |
| port | 1 recorded call plus 10 derived edge cases, all answered by the original PHP |
| verify, wrong port | A port that measures edit distance in bytes, not characters: **rejected**, 4 of 11 calls differ, each one reported with the exact element (`result[12][1]: expected 288, got 433`) |
| verify, reference port | 11 of 11 match. Per call, averaged over the cases: PHP 423 ms, native 0.87 ms (**486×**), JIT on |
| bench, whole app | 598 ms → 51 ms (**11.7×**), output identical. Most of the 51 ms is PHP starting up (~45 ms here). |
| disable | `fuzzy.php` back to its original bytes (`cmp`) |

fast-anything reports 55× for its version of this example, with the port called through FFI + JSON.
Here the port itself is 486× faster than JIT-on PHP; what is left is the process.

## How each step works

**scan**
- Runs the script three times with `php -n`: JIT off, JIT on, and JIT on under `fastphp-runtime` (the observer profiler from Phase 0).
- The extensions php.ini would load are added back by name. Xdebug, pcov, Blackfire and other profilers are left out, and fast-php says so.
- It then parses every file the profile touched (nikic/php-parser) and builds the call graph:
  - direct calls;
  - `self::`/`static::` calls;
  - callables written as `[self::class, 'normalize']` and as strings (`'App\slugify'`).
- It flags what a pure Rust function can't reproduce: I/O, randomness and the clock, globals, static properties and static variables, `$this`, objects, by-reference parameters, variadics, generators, `eval`/`include`, output, dynamic calls, superglobals.
- A helper's blockers pass on to every caller.
- Ranking follows fast-anything: the outermost portable function with at least 2% of the run wins, and covers everything it calls.

**port**
- Puts a one-line prologue into the function. It is placed after the `{`, on the same line, so no line numbers move.
- The workload then runs once, with `res/rec.php` loaded as `auto_prepend_file`:
  - up to 500 calls are kept by reservoir sampling;
  - arguments and results are stored with `serialize()`, so types and keys are exact;
  - an exception is stored as its class and message;
  - `random_int` is used for sampling, so the app's `mt_rand` sequence is undisturbed.
- At shutdown it derives edge cases from the first recorded calls and lets the original PHP answer them:
  - empty, single-element, half and quarter arrays;
  - the same arrays with shrunk elements;
  - strings with `\f`, tab, `\0` and Unicode upper case, which PHP's ASCII-only `strtolower`, `trim` and `\s` treat in their own way;
  - 0, ±1 and neighbouring ints.
- The prologue is then removed, and the file is checked to be byte-identical. If a run is killed mid-recording, the next command removes the leftover prologue.
- Then it writes the crate in `.fast-php/ports/<id>/`:
  - **`src/glue.rs`** (generated): an ext-php-rs function named `fastphp\<id>`. It reads arguments with `fastphp_value::FromPhp`.
  - **`src/port.rs`**: a typed stub for the agent.
  - **`TASK.md`**:
    - the original and its helpers;
    - a signature table: PHP type, the type the recordings showed, Rust type;
    - PHP semantics notes for every builtin the code calls;
    - sample calls.

**Types and fallback**
- Argument types come from the declaration when it is exact (`int`, `?float`), and otherwise from the recordings (`array` → `list<string>` → `Vec<String>`).
- Narrow types are safe because conversions never coerce:
  - an int is not an `f64`;
  - an array with a gap or with string keys is not a `Vec`;
  - non-UTF-8 bytes are not a `String`.
- When a call brings such a value, the native function returns null and the prologue lets the original PHP run. `fastphp-value` was made exact for this; Phase 0's version widened ints to floats and dropped keys.
- A port can also decline an input on purpose with `PhpError::fallback()`.
- This fallback needs a result that is never null. For functions that can return null, the glue has no fallback, and types come from the declarations alone.
- A panic inside a port becomes a PHP `Error` instead of unwinding into the engine.

**verify**
- Builds the port as a standalone extension and replays every case inside PHP (`res/replay.php`), comparing with `StrictComparator`:
  - like `===`, it compares types, key order, key types and bytes;
  - unlike `===`, NAN equals NAN and `-0.0` differs from `0.0`;
  - an expected exception must come back with the same class; a different message is a warning.
- When the original's file is only declarations, it is loaded on its own (with any fast-php prologue stripped), and both versions are timed per call with the JIT on, interleaved.
- A port is verified when every case matches and it is faster.

**enable / disable**
- `enable` inserts the prologue:

  ```php
  if (\function_exists('fastphp\\Fuzzy_findDuplicates') && null !== $__fastphp = \fastphp\Fuzzy_findDuplicates($names, $maxDistance)) return $__fastphp;
  ```

  It also builds `.fast-php/build/fastphp_app` from every enabled port.
- Without the extension loaded, the app runs as before.
- `FAST_PHP_DISABLE=1` loads the extension with no functions.
- `disable` removes the prologue byte for byte.

**bench** runs the workload interleaved with the extension disabled and enabled, JIT on. It reports medians and fails if the output differs.

## Tests

- `vendor/bin/phpunit`: 49 tests.
  - The patcher: insert then strip gives the same bytes and the same line count, for LF, CRLF, braces on the same line, comments, defaults with parentheses, attributes and namespaces.
  - StrictComparator: every case JSON lets through.
  - Type inference.
  - Blockers, the call graph and ranking, on a fixture.
- `sh tests/e2e.sh`: the pipeline above, including the wrong port and three runs (no extension, extension, `FAST_PHP_DISABLE=1`) with identical output.

## Not done yet, and where it goes

- Instance methods (readonly `$this`), PHPDoc types, `fastphp-std` and differential fuzzing → Phase 2.
- Web workloads (`php -S`, FPM, per-worker recording shards; `Cases::merge` already merges shards) → Phase 3.
- Running a coding agent (`go --agent`) and retrying with verify feedback → Phase 4.
- The source-instrumentation profiler fallback from the plan isn't needed so far: the observer profiler worked everywhere it was tried.
- ZTS builds and Windows are still untested.
