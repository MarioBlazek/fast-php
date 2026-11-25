#!/usr/bin/env sh
# The whole pipeline on every example, without an agent:  sh tests/e2e.sh [example]
#
# For each one: scan → a wrong port must fail verify → the reference port verifies → enable →
# the same output with and without the extension → bench → disable restores the source byte
# for byte. A web example is scanned, recorded and benchmarked through php -S with its
# requests.http, and bench fails unless every response is identical with and without the ports.
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)
# Share one cargo cache between runs (CI caches rust/target).
export FAST_PHP_TARGET_DIR="${FAST_PHP_TARGET_DIR:-$root/rust/target/e2e}"
# The tool itself runs with php.ini (php-parser needs ext-tokenizer); its timed runs use -n.
fp() { php "$root/bin/fast-php" "$@"; }

# example  port id  file the port patches  wrong port  [web: scan options]
run() {
    example=$1 id=$2 patched=$3 wrong=$4 web=${5:-}
    echo
    echo "######## $example"
    work=$(mktemp -d)
    cp -R "$root/examples/$example/." "$work/"
    rm -rf "$work/reference"
    cd "$work"
    if [ -f composer.json ]; then
        composer dump-autoload --quiet
    fi

    if [ -n "$web" ]; then
        # shellcheck disable=SC2086
        fp scan $web
    else
        fp scan -- php app.php
    fi

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

    if [ -n "$web" ]; then
        fp bench --runs 3
        echo "ok   every response identical with and without the ports"
        fp install --cli --no-fpm
    else
        so=$(ls "$work"/.fast-php/build/*fastphp_app*)
        php -n app.php > plain.txt
        php -n -d extension="$so" app.php > native.txt
        FAST_PHP_DISABLE=1 php -n -d extension="$so" app.php > disabled.txt
        cmp plain.txt native.txt
        cmp plain.txt disabled.txt
        echo "ok   same output without the extension, with it, and with FAST_PHP_DISABLE=1"
        fp bench --runs 3
    fi
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
if [ -z "$only" ] || [ "$only" = shop-web ]; then
    run shop-web Shop_Bundles_cheapest src/Bundles.php shop-wrong-port.rs "--web requests.http --workers 2 -- php -S localhost:8000 -t public"
fi

# fast-php go with a stand-in agent: the first attempt is wrong and touches glue.rs, the second,
# given FEEDBACK.md, is right. go must retry, put glue.rs back, enable, bench and report.
if [ -z "$only" ] || [ "$only" = go ]; then
    echo
    echo "######## go (fuzzy-dedupe-cli, stand-in agent)"
    work=$(mktemp -d)
    cp -R "$root/examples/fuzzy-dedupe-cli/." "$work/"
    rm -rf "$work/reference"
    cd "$work"
    FAKE_AGENT_WRONG="$root/tests/fixtures/fuzzy-wrong-port.rs" FAKE_AGENT_RIGHT="$root/examples/fuzzy-dedupe-cli/reference/port.rs" \
        fp go --agent "sh $root/tests/fixtures/fake-agent.sh" -- php app.php
    port=.fast-php/ports/Fuzzy_findDuplicates
    test -f $port/agent-1.log && test -f $port/agent-2.log && test ! -f $port/agent-3.log
    if grep -q "changed by the agent" $port/src/glue.rs; then
        echo "FAIL: go left the agent's change to glue.rs"
        exit 1
    fi
    grep -q "fast-php:Fuzzy_findDuplicates" fuzzy.php
    grep -q "Fuzzy_findDuplicates" .fast-php/report.md
    grep -q "<svg" .fast-php/report.svg
    echo "ok   go: retried with feedback, restored glue.rs, enabled, benched, reported"
    fp disable --all
    cmp fuzzy.php "$root/examples/fuzzy-dedupe-cli/fuzzy.php"
    cd "$root"
    rm -rf "$work"
fi

