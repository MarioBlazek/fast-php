<?php
declare(strict_types=1);

namespace Shop;

final class Money
{
    public static function format(int $cents): string
    {
        return '€' . number_format($cents / 100, 2, '.', ',');
    }
}
