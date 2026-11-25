# Phase 3: web workloads

Phases 1 and 2 profiled, recorded and benchmarked a CLI script. Most PHP runs behind a web server, so Phase 3 runs the same pipeline on web requests:

- **`scan --web requests.http`** replays a request file against PHP's built-in server (`php -S`), which serves requests the way FPM does: one at a time per worker, opcache shared between workers. It profiles and ranks as before, and also reports what can't be ported (I/O waits, autoloading).
- **`port`** records the hot function across requests and workers, and merges the per-worker shards.
- **`bench`** runs two servers, one with the ports and one without, and compares p50, p95, p99 and requests per second. Every response must have the same status and body.
- **`bench --url`** does the same against a server fast-php doesn't run, such as your FPM behind nginx: one run before the extension is installed, one after.
- **`install`** checks that each PHP-FPM can load the extension and writes its `conf.d` ini line.
- **[`examples/shop-web`](../examples/shop-web)** is a framework-free shop whose checkout finds the cheapest combination of deals. [`tests/laravel/smoke.sh`](../tests/laravel/smoke.sh) runs the same service inside a fresh Laravel app.

## The request file

Three formats, picked from the content:

```http
# .http, as the JetBrains and VS Code HTTP clients write it: ### separates requests
### Checkout
POST /cart
Content-Type: application/json

{"items": [100, 112, 125]}

### Listing
GET /products?page=2
Accept: text/html
```

- **A plain list:** one request per line, `GET /products?page=2` or just `/products?page=2`.
- **A HAR file** exported from the browser's dev tools. Requests to other hosts (CDNs, analytics) are dropped.

Headers are replayed as written, minus the ones the client must set itself (`Host`, `Content-Length`, `Connection`) and `Accept-Encoding`, so responses come back uncompressed and comparable. Absolute URLs keep only their path and query. `--repeat N` replays the file N times per pass. `--workers N` starts N `php -S` workers (`PHP_CLI_SERVER_WORKERS`) and keeps N requests in flight.

## The server

```sh
vendor/bin/fast-php scan --web requests.http -- php -S localhost:8000 -t public [router.php]
```

The part after `--` is the dev-server command the app already uses. fast-php keeps its document root, router and `-d` options, picks its own free port, and runs it like every other run:
- no php.ini;
- the extensions php.ini loads are added back, minus Xdebug and the other profilers;
- opcache on, JIT on or off as the step needs.

Without a router, `php -S` falls back to the document root's `index.php` for any path that isn't a file. So front controllers (`public/index.php` in Laravel, Symfony and most others) need nothing more.

Two details matter for honest numbers:
- **Warm-up.** Every timed pass is preceded by two untimed ones, so opcache has compiled every script and the tracing JIT has compiled the hot loops in each worker. A pass over a request file is short, so scan times three by default and keeps the best. Single passes on a busy machine came out noisy: once JIT on and JIT off looked equal, where three passes show the 1.7× the JIT gives.
- **The profile leaves out the warm-up.** The profiler keeps running totals per process across requests. scan snapshots them after the warm-up and subtracts the snapshot. On Laravel, a cold profile put 51% of the time in Composer's autoloader, because every file was being compiled for the first time. Warm, the autoloader stayed under the 10% threshold, and the hot service's share went from 15% to 41%.

`stop()` takes the workers down with the server: they outlive a `php -S` parent that is terminated, still holding the port.

## What scan adds for web apps

With builtins observed, scan sums the time spent in calls that wait on something outside PHP:
- PDO, mysqli, Redis and Memcached methods;
- curl and stream I/O;
- file reads, `sleep`.

If that is 20% of the time or more, it says so: no port makes a database faster. It also measures Composer's `ClassLoader::loadClass`. At 10% or more, it points at `composer dump-autoload --classmap-authoritative` and `opcache.preload`, because that is bootstrap cost, not a hot function.

## Recording across requests

The recorder (`res/rec.php`, loaded with `auto_prepend_file`) used to write its sample at the end of the process. Under a web server its shutdown runs at the end of every request, and a worker serves many:
- each request appends what it kept to its worker's shard, `rec-<pid>.jsonl`;
- `port` merges the shards, drops duplicates, and samples them down to `--max`;
- only the first request that calls the function, across all workers, derives edge cases (an `fopen(…, 'x')` lock file), so a thousand requests don't derive the same cases a thousand times;
- a shard stops growing at 256 MB.

## The bench

One worker, on the shop example:

```
 ------------ ----------- ----------- -------- ------------
               p50         p95         p99      requests/s
 ------------ ----------- ----------- -------- ------------
  PHP          615.00 µs   8.0 ms      8.4 ms   549
  with ports   286.71 µs   946.00 µs   1.2 ms   2594
 ------------ ----------- ----------- -------- ------------
 [OK] 4.7× faster over the whole replay (71.0 ms → 15.0 ms), p95 8.4× lower; every response identical.
```

How the bench runs:
- **Two servers, interleaved.** Both load the same extension; one starts with `FAST_PHP_DISABLE=1`. Each is warmed up, then passes alternate between them, so a busy moment on the machine hits both.
- **Responses must match.** A response that differs in status or body fails the bench. If the PHP side doesn't agree with itself across passes (timestamps, CSRF tokens), it warns that the app isn't deterministic.

**Against your own server.** fast-php can't switch an extension on in an FPM it doesn't run, so:

```sh
vendor/bin/fast-php bench --url http://localhost:8080 --label before
vendor/bin/fast-php install --write          # then restart FPM
vendor/bin/fast-php bench --url http://localhost:8080 --label after
```

The second run prints both runs side by side, and checks every response against the first by hash. HTTPS and a path prefix (`--url https://host/app`) are supported.

## install

```
FPM: /usr/sbin/php-fpm8.3
  ✓ PHP 8.3.14 (API20230831,NTS) loads it
  would write /etc/php/8.3/fpm/conf.d/99-fastphp.ini:
    ; fast-php: Rust ports of this app's hot functions. Remove this file to run plain PHP.
    extension=/srv/app/.fast-php/build/libfastphp_app.so
```

For every `php-fpm` it finds on `PATH` and in the usual `sbin` directories (or `--fpm`, and the CLI with `--cli`), install checks:
- **The build matches.** PHP's version, thread safety and module API, from `php-fpm -i`, must equal what the extension was built against. PHP refuses anything else, and an FPM of a different minor version than the CLI is common on servers.
- **It loads.** The binary itself starts with only this extension (`php-fpm -n -d extension=… -m`), and the module must show up.

Then it shows the ini file, or writes it with `--write`. `--copy` puts the library in that PHP's `extension_dir`, so a later `fast-php enable` doesn't change what FPM loads until it is installed again. It prints the restart command (`systemctl restart php8.3-fpm`, `brew services restart php`) and the deploy notes:
- the prologues are ordinary source changes;
- with `opcache.validate_timestamps=0`, reset opcache as on any deploy;
- FPM's environment is read when its master starts, so `FAST_PHP_DISABLE=1` goes in the service's environment (systemd `Environment=`), followed by a restart.

Homebrew's CLI and FPM share one `conf.d`, and install notices that.

## The examples

**[`examples/shop-web`](../examples/shop-web)** is a front controller, a 36-product catalogue in a PHP file (opcache keeps it in shared memory), and deals:
- **Multi-buy:** any 3 of a category, the cheapest free.
- **Bundles:** these SKUs together for a price.

[`Bundles::cheapest`](../examples/shop-web/src/Bundles.php) finds the cheapest way to pay for a cart. It is dynamic programming over the subsets of items paid so far, 2^n states, with integer and bit arithmetic only. The request file has 39 requests:
- checkouts of 14 to 20 items, by query string and by JSON body;
- listings and a 404;
- an unknown SKU;
- a 21-item cart, which makes `cheapest()` throw a `LengthException` that the controller turns into a 422. That one becomes a recorded exception case: the port must throw the same class with the same message.

[`reference/port.rs`](../examples/shop-web/reference/port.rs) is the same algorithm, with the same state order and the same strict `<`, so ties pick the same deal. Where PHP's int addition would overflow into a float, it declines with `checked_add`. The PHPDoc `list<array{int, list<int>}>` gives it `Vec<(i64, Vec<i64>)>`. That needed a fix: a tuple's recorded values used to be joined into one element shape (`int` and `list<int>` join to `mixed`), so `Types::refine()` now infers each tuple position from the recorded values.

**Laravel.** [`tests/laravel/smoke.sh`](../tests/laravel/smoke.sh) creates a fresh app, puts `Bundles` in `app/Services`, and adds a `/quote/{items}/{seed}` route. Then it runs scan, port, verify, enable, bench and disable through `public/index.php`. Parsing a framework's files took the tool past php.ini's 128 MB, so `bin/fast-php` now lifts its own memory limit.

## Results

JIT on, PHP 8.5, macOS, as `tests/e2e.sh` and `tests/laravel/smoke.sh` run them (2 workers):

| | per call (verify) | whole replay (bench) | p95 per request |
|---|---:|---:|---:|
| shop-web: `Bundles::cheapest` | 3.3 ms → 0.32 ms (10×) | 54.1 ms → 15.3 ms (3.5×) | 11.0 ms → 1.7 ms |
| Laravel: the same service | 1.6 ms → 0.17 ms (9.5×) | 39.1 ms → 21.8 ms (1.8×) | 10.5 ms → 3.5 ms |

The two rows show what to expect in a framework:
- **Per call**, the port is as fast as in a bare script.
- **Per request**, the framework's own cost stays: routing, middleware and the container, about 2 ms here. It is the same with or without the port.
- **The tail** gains most: p95 drops 3× because the slowest requests are the ones that run the hot path longest.

A per-call gain of 10×, not the fuzzy example's 261×, is what an integer loop looks like: the tracing JIT already compiles it well.

**Wrong ports rejected** (`tests/e2e.sh`). A shop port with the right totals that forgets PHP's `sort()` of the deals fails 34 of 42 calls, starting `result[1][0]: expected 6, got 32`.

## Tests

- **`vendor/bin/phpunit`:** 58 tests. New ones cover:
  - the three request formats, HAR host filtering, header dropping and chunked responses;
  - PHPDoc tuples inferred per position;
  - a replay against `php -S` with 3 workers: responses come back in request order, and stop() frees the port.
- **`sh tests/e2e.sh`:** now also shop-web, with `--workers 2`:
  - the wrong port must fail;
  - bench must find every response identical;
  - `install --cli --no-fpm` must load the extension.
- **`sh tests/laravel/smoke.sh`:** a fresh Laravel app, end to end. CI runs it on PHP 8.4.

## Not done yet

- **FPM profiling and recording.** scan and port run the app under `php -S`, with the profiler and recorder loaded there. Loading them into a running FPM pool is possible (they are an extension and an `auto_prepend_file`) but not automated.
- **Requests that depend on state:** logins, CSRF tokens, a database the requests change. Replay sends the file as it is. A session cookie in the file works, but a CSRF token in a form doesn't.
- **Preload.** scan points at `opcache.preload` when autoloading is heavy, but doesn't write a preload script.
- **Coding-agent runs with retries:** Phase 4.
