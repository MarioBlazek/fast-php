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

**Phase 0 (spikes) is done.** Results are in [docs/phase-0.md](docs/phase-0.md), and the full plan is in [docs/plan.md](docs/plan.md). The `fast-php` Composer tool (scan → port → verify → enable → bench) is Phase 1 and doesn't exist yet.

Highlights from Phase 0, PHP 8.5, time per call:

| call | PHP (JIT on) | fast-anything's FFI + JSON | fast-php extension |
|---|---:|---:|---:|
| noop() | 15 ns | 435 ns | 22 ns |
| sum(1,000 ints) | 1.11 µs | 16.73 µs | **633 ns** |
| levenshtein(2 names) | 3.82 µs | 736 ns | **205 ns** |
| findDuplicates(600 names) | 667 ms | 3.92 ms | **3.02 ms** |

## Try the spikes

You need PHP ≥ 8.2 with its dev headers (`php-config`), Rust (via [rustup](https://rustup.rs)), and libclang.

```sh
sh spike/run.sh
```

This builds the extensions in `rust/`, checks 28 tricky values round-trip exactly, profiles `examples/fuzzy-dedupe-cli` through the observer extension, and prints the crossing-cost tables with the JIT off and on.

## Layout

```
rust/fastphp-value     exact PHP values in Rust + fast zval reads/writes for generated glue
rust/fastphp-runtime   profiler extension (Zend observer API)
rust/spike-ext         Phase 0 spike extension
rust/spike-ffi         fast-anything's FFI + JSON bridge, for comparison
spike/                 round-trip check, crossing-cost benchmark, runner
examples/              apps used as fixtures
docs/                  plan and phase results
scripts/logo.py        draws the logo, wordmarks and social card (docs/assets)
```

## License

MIT. Builds on fast-anything (MIT); see [NOTICE](NOTICE).
