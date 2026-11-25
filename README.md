<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/wordmark-dark.svg">
    <img src="docs/assets/wordmark-light.svg" alt="fast-php" width="560">
  </picture>
</p>

<p align="center"><b>Find the slow 5% of a PHP app, port only that to Rust, prove it behaves the same, and swap it in as a native extension.</b></p>

fast-php is a PHP-first take on [fast-anything](https://github.com/EL4CTEO/fast-anything). The idea is the same: profile the app, let a coding agent port only the hot functions to Rust, replay real recorded calls to prove the port is right, and enable it reversibly. The parts that matter for PHP are rebuilt:

- **A native extension instead of FFI + JSON.** A call costs about as much as a PHP call (~22 ns), not ~450 ns. It works under PHP-FPM, where FFI is off by default.
- **Exact PHP values.** Int vs string keys, key order, `3` vs `3.0`, binary strings and `INF` all survive the trip. Verification compares with `===`.
- **Profiling through the Zend observer API.** No source edits, builtins are visible, and it works across web requests (`php -S`, FPM).
- **Honest baselines.** Measured with Xdebug and friends unloaded and the JIT on.
- **CLI scripts and web requests** as workloads.

## Status

**Phase 1 is done:** the `fast-php` tool takes a PHP script from profile to an enabled Rust port, and CI runs the whole pipeline on PHP 8.2–8.5 ([docs/phase-1.md](docs/phase-1.md)). Phase 0's spikes are in [docs/phase-0.md](docs/phase-0.md), the plan in [docs/plan.md](docs/plan.md). Web workloads (FPM) are Phase 3 and coding-agent runs are Phase 4.

On the [fuzzy-dedupe example](examples/fuzzy-dedupe-cli), JIT on:

| | PHP | with the Rust port |
|---|---:|---:|
| `Fuzzy::findDuplicates`, per call | 423 ms | **0.87 ms** (486×) |
| the whole script | 598 ms | **51 ms** (11.7×), output identical; most of it is PHP starting up |

## Usage

You need PHP ≥ 8.2 with its dev headers (`php-config`), Rust (via [rustup](https://rustup.rs)) and libclang. `doctor` checks all of it.

It isn't on Packagist yet; install it from this repository:

```sh
composer config repositories.fast-php vcs https://github.com/MarioBlazek/fast-php
composer require --dev marioblazek/fast-php:dev-main

vendor/bin/fast-php doctor
vendor/bin/fast-php scan -- php bin/import.php data.csv     # profile and rank (JIT off and on, no php.ini)
vendor/bin/fast-php port Fuzzy_findDuplicates               # record real calls, write the crate and TASK.md
#   ... a coding agent (or you) writes .fast-php/ports/Fuzzy_findDuplicates/src/port.rs from TASK.md ...
vendor/bin/fast-php verify Fuzzy_findDuplicates             # replay every call inside PHP, compare with ===, time it
vendor/bin/fast-php enable Fuzzy_findDuplicates             # add the prologue, build .fast-php/build/fastphp_app
vendor/bin/fast-php bench                                   # whole app, with and without, output must match
vendor/bin/fast-php disable Fuzzy_findDuplicates            # source back byte for byte
```

The prologue only calls the port when the extension is loaded (`php -d extension=.fast-php/build/libfastphp_app.so`), so a deploy without it runs the original PHP. `FAST_PHP_DISABLE=1` switches the ports off. A call whose arguments fall outside the port's types (a list with a gap, non-UTF-8 bytes, an int where floats were recorded) also runs the original PHP.

Highlights from Phase 0, PHP 8.5, time per call:

| call | PHP (JIT on) | fast-anything's FFI + JSON | fast-php extension |
|---|---:|---:|---:|
| noop() | 15 ns | 435 ns | 22 ns |
| sum(1,000 ints) | 1.11 µs | 16.73 µs | **633 ns** |
| levenshtein(2 names) | 3.82 µs | 736 ns | **205 ns** |
| findDuplicates(600 names) | 667 ms | 3.92 ms | **3.02 ms** |

## Development

```sh
composer install
vendor/bin/phpunit       # patcher, comparator, types, analysis
sh tests/e2e.sh          # the whole pipeline on the example, including a wrong port that must fail verify
sh spike/run.sh          # Phase 0: exact round-trips, observer profiler, crossing costs
```

## Layout

```
bin/fast-php           the CLI
src/Analysis           php-parser: functions, call graph, blockers, ranking
src/Patch              marked one-line prologues, removed byte for byte
src/Port               type inference, crate scaffolding, TASK.md
src/Verify             StrictComparator (=== with NAN and -0.0 handled)
src/Cli                doctor, scan, port, verify, enable, disable, bench, status
res/rec.php            the recorder (auto_prepend_file during `port`)
res/replay.php         the PHP side of `verify`
rust/fastphp-value     exact PHP values in Rust + fast zval reads/writes for generated glue
rust/fastphp-runtime   profiler extension (Zend observer API)
rust/spike-ext         Phase 0 spike extension
rust/spike-ffi         fast-anything's FFI + JSON bridge, for comparison
spike/                 Phase 0 round-trip check, crossing-cost benchmark, runner
examples/              apps used as fixtures (with reference ports)
tests/                 PHPUnit tests, e2e.sh
docs/                  plan and phase results
scripts/logo.py        draws the logo, wordmarks and social card (docs/assets)
```

## License

MIT. Builds on fast-anything (MIT); see [NOTICE](NOTICE).
