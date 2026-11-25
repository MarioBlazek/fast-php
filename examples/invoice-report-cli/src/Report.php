<?php
declare(strict_types=1);

namespace Invoice;

use Invoice\Text\Slug;

final class Report
{
    public function __construct(
        private readonly float $taxRate,
        private readonly string $currency,
        private readonly int $decimals = 2,
    ) {
    }

    /**
     * Gross totals per customer for lines with a valid SKU, sorted by customer slug.
     *
     * @param list<string> $lines "sku;qty;unit price;customer"
     */
    public function summary(array $lines): string
    {
        $totals = [];
        foreach ($lines as $line) {
            [$sku, $qty, $price, $customer] = explode(';', $line);
            if (!Sku::valid($sku)) {
                continue;
            }
            $key = Slug::make($customer);
            $amount = round((int) $qty * (float) $price * (1 + $this->taxRate), $this->decimals);
            $totals[$key] = ($totals[$key] ?? 0) + $amount;
        }
        ksort($totals);
        $out = '';
        foreach ($totals as $customer => $total) {
            $out .= str_pad((string) $customer, 24) . ' ' . number_format($total, $this->decimals, '.', ',') . ' ' . $this->currency . "\n";
        }
        return $out;
    }
}
