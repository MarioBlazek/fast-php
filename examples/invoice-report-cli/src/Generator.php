<?php
declare(strict_types=1);

namespace Invoice;

final class Generator
{
    private const CUSTOMERS = [
        'Copper Kettle Co', '  Lakeside Tools', 'Green Valley Farms ', 'Red Fern Studio', 'Summit Hardware',
        'Blue Harbor Ltd.', 'Orchard & Sons', 'Paper Crane Books', 'Silver Birch Cafe', 'Night Owl Printing',
        'Quarry Road Garage', 'Tin Roof Records', 'Willow Lane Florist', 'Harbor Light Bakery', 'Iron Gate Locksmiths',
    ];

    /** @return list<string> "sku;qty;unit price;customer" */
    public static function lines(int $count, int $seed): array
    {
        mt_srand($seed);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $code = (string) mt_rand(100000, 999999);
            // One in ten codes gets a wrong check digit.
            $sku = 'SKU-' . $code . (mt_rand(1, 10) === 1 ? (self::check($code) + 1) % 10 : self::check($code));
            $out[] = sprintf('%s;%d;%.2f;%s', $sku, mt_rand(1, 12), mt_rand(99, 99999) / 100, self::CUSTOMERS[mt_rand(0, count(self::CUSTOMERS) - 1)]);
        }
        return $out;
    }

    /** The Luhn check digit for a string of digits. */
    private static function check(string $digits): int
    {
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $c) {
            $d = (int) $c * ($i % 2 === 0 ? 2 : 1);
            $sum += $d > 9 ? $d - 9 : $d;
        }
        return (10 - $sum % 10) % 10;
    }
}
