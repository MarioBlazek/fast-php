<?php
declare(strict_types=1);

namespace Shop;

/** The cheapest way to pay for a cart, given its item prices and the deals that apply to it. */
final class Bundles
{
    public const MAX_ITEMS = 20;

    /**
     * Each item is paid either on its own or as part of exactly one deal. Dynamic programming
     * over the sets of items paid for so far: 2^n states, so carts stay under MAX_ITEMS.
     *
     * @param list<int> $prices item prices in cents
     * @param list<array{int, list<int>}> $offers each deal's price in cents, and the items (indexes into $prices) it covers
     * @return array{int, list<int>} the total in cents, and the deals used (indexes into $offers, ascending)
     */
    public static function cheapest(array $prices, array $offers): array
    {
        $n = count($prices);
        if ($n > self::MAX_ITEMS) {
            throw new \LengthException("A cart of $n items is too large to price deals for (at most " . self::MAX_ITEMS . ')');
        }
        // A deal that fits a state starts at the state's lowest unpaid item, so index deals by
        // their lowest item.
        $byItem = array_fill(0, $n, []);
        $masks = [];
        foreach ($offers as $k => [$price, $items]) {
            $mask = 0;
            foreach ($items as $item) {
                if ($item < 0 || $item >= $n) {
                    throw new \OutOfRangeException("Deal $k covers item $item, but the cart has $n items");
                }
                $mask |= 1 << $item;
            }
            $masks[$k] = $mask;
            if ($mask !== 0) {
                $byItem[self::lowest($mask)][] = $k;
            }
        }

        $full = (1 << $n) - 1;
        $cost = array_fill(0, $full + 1, PHP_INT_MAX);
        $via = array_fill(0, $full + 1, 0);
        $cost[0] = 0;
        for ($paid = 0; $paid < $full; $paid++) {
            $c = $cost[$paid];
            if ($c === PHP_INT_MAX) {
                continue;
            }
            $i = self::lowest(~$paid & $full);
            $next = $paid | (1 << $i);
            if ($c + $prices[$i] < $cost[$next]) {
                $cost[$next] = $c + $prices[$i];
                $via[$next] = -1 - $i;
            }
            foreach ($byItem[$i] as $k) {
                if (($masks[$k] & $paid) !== 0) {
                    continue;
                }
                $next = $paid | $masks[$k];
                if ($c + $offers[$k][0] < $cost[$next]) {
                    $cost[$next] = $c + $offers[$k][0];
                    $via[$next] = $k;
                }
            }
        }

        $used = [];
        for ($paid = $full; $paid !== 0;) {
            $v = $via[$paid];
            if ($v >= 0) {
                $used[] = $v;
                $paid &= ~$masks[$v];
            } else {
                $paid &= ~(1 << (-1 - $v));
            }
        }
        sort($used);
        return [$cost[$full], $used];
    }

    private static function lowest(int $mask): int
    {
        $i = 0;
        while (($mask & 1) === 0) {
            $mask >>= 1;
            $i++;
        }
        return $i;
    }
}
