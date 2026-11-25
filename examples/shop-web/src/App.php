<?php
declare(strict_types=1);

namespace Shop;

final class App
{
    /**
     * @param array<int, array{string, string, int}> $catalog sku => [name, category, price in cents]
     * @param array{multibuy: array<string, int>, bundles: list<array{list<int>, int}>} $deals
     */
    public function __construct(private readonly array $catalog, private readonly array $deals)
    {
    }

    public function handle(string $method, string $uri, string $body): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        match (true) {
            $path === '/' => $this->home(),
            $path === '/cart' && $method === 'GET' => $this->cart(array_map('intval', array_filter(explode(',', (string) ($query['items'] ?? '')), 'strlen'))),
            $path === '/cart' && $method === 'POST' => $this->cart(array_map('intval', (array) (json_decode($body, true)['items'] ?? []))),
            default => $this->json(404, ['error' => "No page $path"]),
        };
    }

    private function home(): void
    {
        $byCategory = [];
        foreach ($this->catalog as $sku => [$name, $category, $price]) {
            $byCategory[$category][] = sprintf('<li><a href="/product/%d-%s">%s</a> %s</li>', $sku, Text::slug($name), Text::e($name), Money::format($price));
        }
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><title>Shop</title><h1>Shop</h1>\n";
        foreach ($byCategory as $category => $items) {
            echo '<h2>', Text::e(ucfirst($category)), "</h2>\n<ul>\n", implode("\n", $items), "\n</ul>\n";
        }
    }

    /** @param list<int> $skus */
    private function cart(array $skus): void
    {
        $prices = [];
        foreach ($skus as $sku) {
            if (!isset($this->catalog[$sku])) {
                $this->json(422, ['error' => "Unknown SKU $sku"]);
                return;
            }
            $prices[] = $this->catalog[$sku][2];
        }
        [$offers, $names] = $this->offers($skus);
        try {
            [$total, $used] = Bundles::cheapest($prices, $offers);
        } catch (\LengthException $e) {
            $this->json(422, ['error' => $e->getMessage()]);
            return;
        }
        $regular = array_sum($prices);
        $this->json(200, [
            'items' => count($skus),
            'regular' => Money::format($regular),
            'total' => Money::format($total),
            'saved' => Money::format($regular - $total),
            'deals' => array_map(static fn (int $k) => $names[$k], $used),
        ]);
    }

    /**
     * The deals that apply to this cart, as Bundles::cheapest() takes them.
     *
     * @param list<int> $skus
     * @return array{0: list<array{int, list<int>}>, 1: list<string>}
     */
    private function offers(array $skus): array
    {
        $offers = [];
        $names = [];
        $byCategory = [];
        foreach ($skus as $i => $sku) {
            $byCategory[$this->catalog[$sku][1]][] = $i;
        }
        // Multi-buy: every choice of N items of the category, the cheapest one free.
        foreach ($this->deals['multibuy'] as $category => $n) {
            foreach (self::choose($byCategory[$category] ?? [], $n) as $items) {
                $prices = array_map(fn (int $i) => $this->catalog[$skus[$i]][2], $items);
                $offers[] = [array_sum($prices) - min($prices), $items];
                $names[] = "$n for " . ($n - 1) . ': ' . implode(', ', array_map(fn (int $i) => $this->catalog[$skus[$i]][0], $items));
            }
        }
        // Bundles: the first item of each SKU in the cart.
        $first = [];
        foreach ($skus as $i => $sku) {
            $first[$sku] ??= $i;
        }
        foreach ($this->deals['bundles'] as [$bundle, $price]) {
            if (array_diff($bundle, array_keys($first)) === []) {
                $offers[] = [$price, array_map(static fn (int $sku) => $first[$sku], $bundle)];
                $names[] = 'Bundle: ' . implode(' + ', array_map(fn (int $sku) => $this->catalog[$sku][0], $bundle)) . ' for ' . Money::format($price);
            }
        }
        return [$offers, $names];
    }

    /**
     * @param list<int> $from
     * @return list<list<int>> every $k-element subset of $from, in order
     */
    private static function choose(array $from, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        $out = [];
        for ($i = 0; $i + $k <= count($from); $i++) {
            foreach (self::choose(array_slice($from, $i + 1), $k - 1) as $rest) {
                $out[] = [$from[$i], ...$rest];
            }
        }
        return $out;
    }

    private function json(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }
}
