#!/usr/bin/env sh
# The whole pipeline on examples/fuzzy-dedupe-cli, without an agent:  sh tests/e2e.sh
# scan → a wrong port must fail verify → the reference port verifies → enable → same output
# with and without the extension → bench → disable restores the source byte for byte.
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
cp "$root"/examples/fuzzy-dedupe-cli/*.php "$work"/
cd "$work"
# Share one cargo cache between runs (CI caches rust/target).
export FAST_PHP_TARGET_DIR="${FAST_PHP_TARGET_DIR:-$root/rust/target/e2e}"
fp() { php -n "$root/bin/fast-php" "$@"; }

fp doctor
fp scan -- php app.php

echo "== a port that counts bytes instead of characters must fail verify"
fp port Fuzzy_findDuplicates --reference "$root/tests/fixtures/fuzzy-wrong-port.rs"
if fp verify Fuzzy_findDuplicates; then
    echo "FAIL: verify accepted a wrong port"
    exit 1
fi

echo "== the reference port"
fp port Fuzzy_findDuplicates --reference "$root/examples/fuzzy-dedupe-cli/reference/port.rs"
fp verify Fuzzy_findDuplicates
fp enable Fuzzy_findDuplicates
grep -q 'fast-php:Fuzzy_findDuplicates' fuzzy.php

so=$(ls "$work"/.fast-php/build/*fastphp_app*)
php -n app.php > plain.txt
php -n -d extension="$so" app.php > native.txt
FAST_PHP_DISABLE=1 php -n -d extension="$so" app.php > disabled.txt
cmp plain.txt native.txt
cmp plain.txt disabled.txt
echo "ok   same output without the extension, with it, and with FAST_PHP_DISABLE=1"

fp bench --runs 3
fp disable Fuzzy_findDuplicates
cmp fuzzy.php "$root/examples/fuzzy-dedupe-cli/fuzzy.php"
echo "ok   disable restored fuzzy.php byte for byte"
