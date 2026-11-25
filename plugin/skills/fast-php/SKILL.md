---
name: fast-php
description: Speed up a PHP application by porting its hottest pure functions to Rust with the fast-php tool (profile, record real calls, write the port, verify with ===, enable as a native extension, benchmark). Use when the user wants a PHP script, job or web app to run faster, asks to profile PHP or port PHP to Rust, or works in a project with a .fast-php/ directory or a fast-php TASK.md.
---

# Speeding up PHP with fast-php

fast-php finds the functions a PHP workload spends its time in, records real calls of the hottest portable one, and has a coding agent (you) write the same function in Rust. It proves the port right by replaying every recorded call against it, compared with `===`, exceptions and messages included. Then it swaps the port in with a one-line prologue that runs it only when the native extension is loaded.

You are the agent here: write `port.rs` yourself and use the CLI for everything else. Never hand-edit the generated glue, the recorded cases or the app's PHP source; `disable` and `enable` do the source changes, and they are reversible byte for byte.

## Setup

```sh
composer config repositories.fast-php vcs https://github.com/MarioBlazek/fast-php   # not on Packagist yet
composer require --dev marioblazek/fast-php:dev-main
vendor/bin/fast-php doctor     # PHP ≥ 8.2 with php-config, Rust ≥ 1.88, libclang
```

If `doctor` reports a problem, show the user the fix it prints; don't work around it.

## The loop

1. **Scan.** CLI: `vendor/bin/fast-php scan -- php bin/script.php args`. Web: `vendor/bin/fast-php scan --web requests.http -- php -S localhost:8000 -t public`. Requests are an `.http` file (requests separated by `###`), a list of `GET /path` lines, or a HAR export; they should look like real traffic. Read the table:
   - `port`: a candidate. The first one is the best.
   - `covered by X`: X's port includes it.
   - `blocked: …`: it can't be ported as it is. The reason says why (I/O, globals, writes to `$this`…).
   - The notes matter. If the time is mostly I/O waits or autoloading, say so to the user instead of porting. If most of a candidate's time is in builtins, the gain will be small.
2. **Port.** `vendor/bin/fast-php port <Id>` records up to 500 real calls plus derived edge cases, and writes `.fast-php/ports/<Id>/`:
   - `TASK.md`: the original and its helpers, the Rust signature, the PHP semantics the port must match, sample calls. Read it fully.
   - `src/port.rs`: the only file you edit.
3. **Write `src/port.rs`.**
   - Translate the PHP faithfully, helpers included, keeping its order of operations (ties and iteration order show up in results).
   - Use `fastphp_std` for every PHP builtin: string, `preg_*` (PCRE2), `round`, `number_format`, comparisons and sorting, `mb_*`. Rust's own `trim`, `to_lowercase`, `regex` and float formatting all differ from PHP's.
   - Throw what PHP throws: `Err(PhpError::new("ValueError", "exact message"))`.
   - Where PHP would warn, or int arithmetic would overflow into a float, return `Err(PhpError::fallback())`. PHP then runs that call itself, so a fallback is always correct, just not faster.
4. **Verify.** `vendor/bin/fast-php verify <Id>` builds the crate and replays every call. Fix every reported difference: each shows the case, the arguments and the first point where the results part. It must also be faster than PHP per call. Repeat 3 and 4 until it passes. Don't loosen the check (`--loose-messages`) unless the user agrees.
5. **Enable and measure.**
   - `vendor/bin/fast-php enable <Id>`, then `vendor/bin/fast-php bench`. The bench fails if any output or response differs.
   - `vendor/bin/fast-php report` writes `.fast-php/report.md` and `report.svg`.
   - For PHP-FPM: `vendor/bin/fast-php install` checks FPM can load the extension, and `--write` adds its ini line.

`vendor/bin/fast-php go --agent claude -- php app.php` runs the same loop unattended, with a separate Claude Code process as the agent. That is for CI or batch runs; inside a session, do the steps yourself.

## Reporting back

Tell the user:
- what was ported, the per-call and end-to-end speedups from verify and bench, and that outputs were identical;
- what was left out and why (blocked functions, I/O-bound time);
- that `vendor/bin/fast-php disable --all` undoes the source changes, and that without the extension loaded the app runs its original PHP.

Don't commit unless the user asks.
