<?php
declare(strict_types=1);

namespace Invoice\Text;

final class Ascii
{
    private const FOLD = ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss'];

    public static function fold(string $s): string
    {
        return str_replace(array_keys(self::FOLD), array_values(self::FOLD), $s);
    }
}
