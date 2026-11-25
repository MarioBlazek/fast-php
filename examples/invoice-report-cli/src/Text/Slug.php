<?php
declare(strict_types=1);

namespace Invoice\Text;

final class Slug
{
    public static function make(string $name): string
    {
        $s = strtolower(trim($name));
        if (preg_match('/[^\x00-\x7F]/', $s)) {
            // Only for names with accents; the example data has none, so Ascii is never loaded.
            $s = Ascii::fold($s);
        }
        return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    }
}
