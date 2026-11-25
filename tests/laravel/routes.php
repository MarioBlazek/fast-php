
// fast-php's Laravel smoke test: a quote endpoint whose deal pricing is the hot path.
Route::get('/quote/{items}/{seed}', function (int $items, int $seed) {
    // A deterministic cart of $items items, and deals over it, from the seed.
    $prices = [];
    $x = $seed;
    for ($i = 0; $i < $items; $i++) {
        $x = ($x * 1103515245 + 12345) % 2147483648;
        $prices[] = 990 + $x % 3000;
    }
    $offers = [];
    for ($k = 0; $k < 4 * $items; $k++) {
        $x = ($x * 1103515245 + 12345) % 2147483648;
        $a = $x % $items;
        $b = intdiv($x, 7) % $items;
        $c = intdiv($x, 49) % $items;
        $members = array_values(array_unique([$a, $b, $c]));
        $sum = 0;
        foreach ($members as $m) {
            $sum += $prices[$m];
        }
        $offers[] = [intdiv($sum * 85, 100), $members];
    }
    [$total, $used] = \App\Services\Bundles::cheapest($prices, $offers);
    return response()->json(['items' => $items, 'total' => $total, 'deals' => $used]);
})->whereNumber(['items', 'seed']);
