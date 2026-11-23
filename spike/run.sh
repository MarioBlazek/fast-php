#!/usr/bin/env sh
# Phase 0 spikes, end to end:  sh spike/run.sh
# Builds the extensions, checks values round-trip exactly, profiles the example app through the
# observer API, and prints the crossing-cost table with the JIT off and on.
set -eu
cd "$(dirname "$0")/.."
root=$PWD
(cd rust && cargo build --release --quiet)
case "$(uname)" in Darwin) so=dylib ;; *) so=so ;; esac
ext="$root/rust/target/release/libspike_ext.$so"
ffi="$root/rust/target/release/libspike_ffi.$so"
runtime="$root/rust/target/release/libfastphp_runtime.$so"
# -n: no php.ini. A debugger or profiler loaded from php.ini (Xdebug) would skew every number.
php="php -n"
$php -r 'exit(extension_loaded("ffi") ? 0 : 1);' || php="$php -d extension=ffi"

echo "== exact values: PHP -> Rust -> PHP"
log=$(mktemp)
if ! $php -d extension="$ext" spike/roundtrip.php >"$log" 2>&1; then cat "$log"; exit 1; fi
tail -n 4 "$log"
rm -f "$log"

echo
echo "== observer profiler on examples/fuzzy-dedupe-cli"
out=$(mktemp -d)
FASTPHP_PROF_DIR="$out" $php -d extension="$runtime" examples/fuzzy-dedupe-cli/app.php >/dev/null
$php -r '
    $p = json_decode(file_get_contents(glob($argv[1] . "/prof-*.json")[0]), true);
    usort($p["functions"], fn($a, $b) => $b["cum"] <=> $a["cum"]);
    printf("wall %.3fs\n", $p["wall"]);
    foreach ($p["functions"] as $f) printf("  %-28s %8d calls  own %.3fs  cum %.3fs\n", ($f["class"] ? $f["class"] . "::" : "") . $f["name"], $f["calls"], $f["own"], $f["cum"]);
' "$out"
rm -rf "$out"

echo
echo "== crossing cost"
$php -d extension="$ext" spike/crossing.php "$ffi"
echo
$php -d extension="$ext" -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M spike/crossing.php "$ffi"
