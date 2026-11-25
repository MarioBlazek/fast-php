#!/usr/bin/env sh
# Differential fuzzing of fastphp-std against this PHP:  sh tests/std/run.sh [runs] [seed]
# Fixed seeds keep CI reproducible; one random seed per run keeps finding new inputs.
set -eu
cd "$(dirname "$0")/../.."
(cd rust && cargo build --release --quiet -p std-check)
case "$(uname)" in Darwin) so=dylib ;; *) so=so ;; esac
php="php -n -d extension=$PWD/rust/target/release/libstd_check.$so"
$php -r 'exit(extension_loaded("mbstring") ? 0 : 1);' || php="$php -d extension=mbstring"
runs="${1:-3000}"
if [ $# -ge 2 ]; then
    $php tests/std/fuzz.php "$2" "$runs"
    exit
fi
log=$(mktemp)
trap 'rm -f "$log"' EXIT
for seed in 1 2 3; do
    if ! $php tests/std/fuzz.php "$seed" "$runs" >"$log" 2>&1; then
        cat "$log"
        exit 1
    fi
    tail -n 1 "$log"
done
$php tests/std/fuzz.php "$(od -An -N4 -tu4 /dev/urandom | tr -d ' ')" "$runs"
