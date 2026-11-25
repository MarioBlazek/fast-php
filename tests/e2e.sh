#!/usr/bin/env sh
# The whole pipeline on every example, without an agent:  sh tests/e2e.sh [example]
#
# For each one: scan → a wrong port must fail verify → the reference port verifies → enable →
# the same output with and without the extension → bench → disable restores the source byte
# for byte.
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)
# Share one cargo cache between runs (CI caches rust/target).
export FAST_PHP_TARGET_DIR="${FAST_PHP_TARGET_DIR:-$root/rust/target/e2e}"
# The tool itself runs with php.ini (php-parser needs ext-tokenizer); its timed runs use -n.
fp() { php "$root/bin/fast-php" "$@"; }

# example  port id  file the port patches  wrong port (what it gets wrong)
run() {
    example=$1 id=$2 patched=$3 wrong=$4
    echo
    echo "######## $example"
    work=$(mktemp -d)
    cp -R "$root/examples/$example/." "$work/"
    rm -rf "$work/reference"
    cd "$work"
    if [ -f composer.json ]; then
        composer dump-autoload --quiet
    fi

    fp scan -- php app.php

    echo "== $wrong must fail verify"
    fp port "$id" --reference "$root/tests/fixtures/$wrong"
    if fp verify "$id"; then
        echo "FAIL: verify accepted a wrong port ($wrong)"
        exit 1
    fi

    echo "== the reference port"
    fp port "$id" --reference "$root/examples/$example/reference/port.rs"
    fp verify "$id"
    fp enable "$id"
    grep -q "fast-php:$id" "$patched"

    so=$(ls "$work"/.fast-php/build/*fastphp_app*)
    php -n app.php > plain.txt
    php -n -d extension="$so" app.php > native.txt
    FAST_PHP_DISABLE=1 php -n -d extension="$so" app.php > disabled.txt
    cmp plain.txt native.txt
    cmp plain.txt disabled.txt
    echo "ok   same output without the extension, with it, and with FAST_PHP_DISABLE=1"

    fp bench --runs 3
    fp disable "$id"
    cmp "$patched" "$root/examples/$example/$patched"
    echo "ok   disable restored $patched byte for byte"
    cd "$root"
    rm -rf "$work"
}

only=${1:-}
if [ -z "$only" ] || [ "$only" = fuzzy-dedupe-cli ]; then
    run fuzzy-dedupe-cli Fuzzy_findDuplicates fuzzy.php fuzzy-wrong-port.rs
fi
if [ -z "$only" ] || [ "$only" = invoice-report-cli ]; then
    run invoice-report-cli Invoice_Report_summary src/Report.php invoice-wrong-port.rs
fi
