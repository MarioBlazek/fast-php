#!/usr/bin/env sh
# fast-php on a fresh Laravel app:  sh tests/laravel/smoke.sh
#
# composer create-project laravel/laravel, add a service (the shop example's Bundles class) and
# a route that uses it, then: scan --web through public/index.php, port with the reference port,
# verify, enable, bench (every response identical), disable (the source restored byte for byte).
set -eu
root=$(cd "$(dirname "$0")/../.." && pwd)
export FAST_PHP_TARGET_DIR="${FAST_PHP_TARGET_DIR:-$root/rust/target/e2e}"
fp() { php "$root/bin/fast-php" "$@"; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
composer create-project laravel/laravel "$work/app" --prefer-dist --no-interaction --quiet
cd "$work/app"
# No session or cache writes: requests stay independent and the app needs no database.
printf '\nSESSION_DRIVER=array\nCACHE_STORE=array\n' >> .env
mkdir -p app/Services
sed 's/^namespace Shop;/namespace App\\Services;/' "$root/examples/shop-web/src/Bundles.php" > app/Services/Bundles.php
cat "$root/tests/laravel/routes.php" >> routes/web.php
cp app/Services/Bundles.php "$work/Bundles.orig.php"
for n in 14 15 16 17 18 19 20 18 17 16 19 20 15 18 17 16; do
    echo "GET /quote/$n/$((n * 7))" >> requests.http
done
echo "GET /" >> requests.http

fp scan --web requests.http --workers 2 -- php -S localhost:8000 -t public
fp port App_Services_Bundles_cheapest --reference "$root/examples/shop-web/reference/port.rs"
fp verify App_Services_Bundles_cheapest
fp enable App_Services_Bundles_cheapest
grep -q "fast-php:App_Services_Bundles_cheapest" app/Services/Bundles.php
fp bench --runs 3
fp disable App_Services_Bundles_cheapest
cmp app/Services/Bundles.php "$work/Bundles.orig.php"
echo "ok   Laravel: scan, port, verify, enable, bench (identical responses), disable"
