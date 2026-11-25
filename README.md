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

**Phase 3 is done** ([docs/phase-3.md](docs/phase-3.md)): web apps.
- **`scan --web requests.http`:** replays `.http`, URL-list or HAR requests against `php -S`, warmed up, with workers.
- **Sharded recording:** across requests and workers.
- **`bench`:** p50/p95/requests per second with and without the ports, and every response must be identical. `--url` does the same against your FPM, before and after.
- **`install`:** checks each PHP-FPM can load the extension and writes its ini line.
- **Laravel:** checked end to end in CI.

Earlier phases:
- [Phase 2](docs/phase-2.md): `fastphp-std` (PHP's builtins in Rust, fuzzed against PHP 8.2–8.5), PHPDoc types, instance methods, exception and warning parity.
- [Phase 1](docs/phase-1.md): the CLI pipeline.
- [Phase 0](docs/phase-0.md): spikes.

The plan is in [docs/plan.md](docs/plan.md). Coding-agent runs are Phase 4.

The examples, JIT on:

| example | per call | whole run |
|---|---:|---:|
| [fuzzy-dedupe-cli](examples/fuzzy-dedupe-cli): `Fuzzy::findDuplicates` | 440 ms → 1.7 ms (261×) | 604 ms → 52 ms (11.5×) |
| [invoice-report-cli](examples/invoice-report-cli): `Report::summary`, an instance method that is half builtins | 12.3 ms → 6.6 ms (1.9×) | 243 ms → 198 ms (1.2×) |
| [shop-web](examples/shop-web): `Bundles::cheapest`, 39 requests via `php -S`, 2 workers | 3.3 ms → 0.32 ms (10×) | 54 ms → 15 ms (3.5×), p95 11.0 → 1.7 ms |
| the same service in a fresh Laravel app | 1.6 ms → 0.17 ms (9.5×) | 39 ms → 22 ms (1.8×), p95 10.5 → 3.5 ms |

All outputs and responses are identical. Code that is mostly PHP loops gains the most; code that is mostly calls to builtins (already C) gains little, and `scan` says so.

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

A web app is the same, with requests instead of a script:

```sh
vendor/bin/fast-php scan --web requests.http -- php -S localhost:8000 -t public
vendor/bin/fast-php port Shop_Bundles_cheapest && vendor/bin/fast-php verify Shop_Bundles_cheapest && vendor/bin/fast-php enable Shop_Bundles_cheapest
vendor/bin/fast-php bench                                   # two php -S servers: p50/p95/RPS, identical responses
vendor/bin/fast-php install --write                         # conf.d ini for PHP-FPM, after checking it loads there
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
vendor/bin/phpunit       # patcher, comparator, types, PHPDoc, analysis
sh tests/std/run.sh      # fastphp-std against this PHP: differential fuzzing
sh tests/e2e.sh          # the whole pipeline on every example, each with a wrong port that must fail verify
sh tests/laravel/smoke.sh  # the web pipeline on a fresh Laravel app (needs network for composer)
sh spike/run.sh          # Phase 0: exact round-trips, observer profiler, crossing costs
```

## Layout

```
bin/fast-php           the CLI
src/Analysis           php-parser: functions, call graph, blockers, ranking, PHPDoc, Composer lookup
src/Patch              marked one-line prologues, removed byte for byte
src/Port               type inference, crate scaffolding, TASK.md
src/Verify             StrictComparator (=== with NAN and -0.0 handled)
src/Workload           what runs: a CLI script, or requests replayed against php -S
src/Web                request files (.http, URL lists, HAR), HTTP client, php -S with workers
src/Cli                doctor, scan, port, verify, enable, disable, bench, install, status
res/rec.php            the recorder (auto_prepend_file during `port`)
res/replay.php         the PHP side of `verify`
rust/fastphp-value     exact PHP values in Rust + fast zval reads/writes for generated glue
rust/fastphp-std       PHP's builtins in Rust, byte for byte (what ports call)
rust/std-check         fastphp-std exposed to PHP for tests/std/fuzz.php
rust/fastphp-runtime   profiler extension (Zend observer API)
rust/spike-ext         Phase 0 spike extension
rust/spike-ffi         fast-anything's FFI + JSON bridge, for comparison
spike/                 Phase 0 round-trip check, crossing-cost benchmark, runner
examples/              apps used as fixtures (with reference ports)
tests/                 PHPUnit tests, e2e.sh, std/ (differential fuzzing)
docs/                  plan and phase results
scripts/logo.py        draws the logo, wordmarks and social card (docs/assets)
```

## License

MIT. Builds on fast-anything (MIT); see [NOTICE](NOTICE).
