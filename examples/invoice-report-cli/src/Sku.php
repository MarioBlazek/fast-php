<?php
declare(strict_types=1);

namespace Invoice;

final class Sku
{
    /** Luhn mod-10 check over the digits of a code like "SKU-1234567": the last digit checks the rest. */
    public static function valid(string $sku): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($sku) - 1; $i >= 0; $i--) {
            $c = $sku[$i];
            if ($c < '0' || $c > '9') {
                continue;
            }
            $d = ord($c) - 48;
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }
}
