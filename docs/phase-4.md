# Phase 4: the agent loop

Phases 1–3 left one step to a person: writing `port.rs` from `TASK.md`. Phase 4 hands that step to a coding agent and closes the loop:

- **`fast-php go --agent claude|codex|'<command>'`** runs the whole pipeline: scan, record, have the agent write the port, verify it, retry with the failures, enable, bench, report.
- **`fast-php report`** writes `.fast-php/report.md` and `report.svg`, for a pull request or a team.
- **A Claude Code plugin** ([`plugin/`](../plugin)): the `/fast-php:fast` command and the `fast-php` skill. With it, the Claude Code session you are in does the porting itself.

## go

```sh
vendor/bin/fast-php go -- php bin/import.php data.csv
vendor/bin/fast-php go --agent codex --web requests.http --workers 2 -- php -S localhost:8000 -t public
vendor/bin/fast-php go --agent 'aider --yes --message "$FASTPHP_PROMPT"' --id Shop_Bundles_cheapest
```

For each candidate (the top one of the scan, `--ports N` for more, or `--id`):

1. **`port`** records the calls and writes the crate and `TASK.md`.
2. **The crate is built once before the agent starts.** Dependencies are fetched and compiled up front (Codex's sandbox has no network), and a broken scaffold isn't blamed on the agent.
3. **The agent runs in the project root** with a short prompt: read `TASK.md`, write `src/port.rs`, run `fast-php verify <id>` until it passes. Its output goes to `ports/<id>/agent-<n>.log`.
4. **Changes outside `port.rs` are put back.** Before the agent starts, go takes a copy of every other file in the crate (the generated glue, `Cargo.toml`, the recorded cases) and of the scanned PHP files. Afterwards it restores whatever the agent changed, and says so in the feedback.
5. **go runs `verify` itself.** The agent may have run it too, but only go's own run counts. If a call differs, the crate doesn't compile, or the port isn't faster than PHP, verify's full output goes to `ports/<id>/FEEDBACK.md`, and the next attempt's prompt points at it. Compiler errors are captured for this: during `go`, cargo's output goes into the error, not to the terminal.
6. **On success**, the port is enabled (unless `--no-enable`).

`--retries` (3) sets how many more attempts follow the first, and `--timeout` (1800 s) how long each may take.

When every port is done, `go` runs `bench` (unless `--no-bench`) and `report`, then prints a summary.

### Agents

| `--agent` | runs | may |
|---|---|---|
| `claude` | `claude -p <prompt> --permission-mode acceptEdits` | edit files, and run only `php …/fast-php verify <id>` (`--allowedTools`) |
| `codex` | `codex exec -C <project> -s workspace-write <prompt>` | write inside the project, run commands without network |
| anything else | `sh -c '<command>'` | whatever the command does |

`--model` passes a model to `claude` or `codex`.

A shell command gets the task in environment variables, so any other agent CLI fits in:

| variable | value |
|---|---|
| `FASTPHP_PROMPT` | the prompt |
| `FASTPHP_ID` | the port id |
| `FASTPHP_TASK` | the path of `TASK.md` |
| `FASTPHP_PORT` | the path of `port.rs` |
| `FASTPHP_FEEDBACK` | the path of `FEEDBACK.md`, empty on the first attempt |
| `FASTPHP_ATTEMPT` | the attempt number |
| `FASTPHP_VERIFY` | the verify command |

`doctor` now lists the agents it finds on `PATH`.

### A real run

`go --agent claude` on [`examples/shop-web`](../examples/shop-web), with no reference port. Claude Code read `TASK.md`, wrote a 91-line `port.rs`, ran verify and checked its own work, in 101 seconds:

```
Attempt 1 of 4: Claude Code writes .fast-php/ports/Shop_Bundles_cheapest/src/port.rs
  agent finished in 101.09 s (log: .fast-php/ports/Shop_Bundles_cheapest/agent-1.log)
    42 of 42 match (5 more fell back to PHP)
    per call: PHP 3.5 ms, native 346.55 µs (10.0×), JIT on
   [OK] Shop_Bundles_cheapest is verified.
 [OK] Shop_Bundles_cheapest is enabled in src/Bundles.php.
 [OK] 3.5× faster over the whole replay (50.7 ms → 14.5 ms), p95 5.8× lower;
```

Without being told, the port carries over:
- the exception classes and messages;
- the strict `<` that decides ties;
- a fallback where an int sum would overflow into a float.

The agent also explained the 5 calls that fell back. It replaced its own fallbacks with an error and saw the counts unchanged, so it was the generated glue's type check that turned those arguments down.

[`tests/e2e.sh`](../tests/e2e.sh) checks the loop without a model. A stand-in agent ([`tests/fixtures/fake-agent.sh`](../tests/fixtures/fake-agent.sh)) writes the wrong fuzzy port on its first attempt and edits `glue.rs`. The test requires that:
- go puts `glue.rs` back;
- go hands over `FEEDBACK.md` with the failed calls;
- the second attempt, given that feedback, writes the right port;
- go then enables, benches and reports.

## report

`vendor/bin/fast-php report [--out DIR]` reads `state.json`. `go` writes the report at the end of every run.

**`report.md`** has:
- the workload and the PHP version;
- JIT off against JIT on;
- the I/O and autoload notes;
- the top ten functions with their verdicts;
- every port with its recorded and derived calls, matches and per-call times;
- the end-to-end bench (p50/p95/requests per second for web);
- how to turn the ports off.

**`report.svg`** draws PHP's time as a full bar and the ports' time as a fraction of it, with the speedup:
- one row per verified port (per call);
- one row for the whole workload;
- for web, one row for the p95.

It follows the reader's light or dark theme.

## The Claude Code plugin

This repository is a plugin marketplace:

```
/plugin marketplace add MarioBlazek/fast-php
/plugin install fast-php@fast-php
```

- **`/fast-php:fast [-- php app.php | --web requests.http -- php -S …]`** speeds up the project the session is in. If no workload is given, it works out how the app runs.
- **The `fast-php` skill** is what the command follows. Claude Code also loads it when you ask to make PHP faster, or work in a project with `.fast-php/`. It covers:
  - setup and `doctor`;
  - reading the scan, including when not to port (I/O-bound time, builtin-heavy code);
  - writing `port.rs` with `fastphp_std`, exact exceptions and fallbacks;
  - the verify loop, without loosening the check;
  - enable, bench, report, install;
  - what to tell the user.

  Inside a session, the session is the agent. `go --agent claude` is for unattended runs.

Both manifests pass `claude plugin validate`.

## Also in this phase

**The builtin-share note names the builtins.** On the shop example, scan said 53% of `Bundles::cheapest`'s time was in builtins, and listed `preg_*` and `explode()`, which it never calls. The 53% is real: it is `array_fill()` creating two tables of 2^20 entries per call. scan now names the builtins the candidate actually calls, ranked by their time in the run: "mostly array_fill()".

## Tests

- **`vendor/bin/phpunit`:** 65 tests. New ones cover:
  - the agent command lines (the claude tool allowlist, the codex sandbox, shell commands);
  - prompts pointing at the feedback;
  - the guard putting files back;
  - the report's Markdown (escaped table cells) and SVG (one bar pair per row, well-formed XML).
- **`sh tests/e2e.sh go`:** the loop with the stand-in agent, as above.

## Not done yet

- **Several ports at once.** `--ports N` ports candidates one after another. Agents could work on them in parallel, one crate each.
- **Cost and token reporting** for agent runs: the logs have the agent's output, but no usage numbers.
- **Packagist.** Install from the repository for now.
