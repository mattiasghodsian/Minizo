<?php

namespace App\Support;

class GeneratedArt
{
    /** Hash a string to a hue in 0..359. */
    public static function hue(string $value): int
    {
        $hue = 0;

        foreach (mb_str_split($value) as $character) {
            // mb_str_split already yields whole characters, so mb_ord cannot fail
            // for valid UTF-8. The cast covers the one edge case it does not:
            // a filesystem name in some other encoding, where mb_ord returns
            // false. Such a character contributes 0 - deterministic, and it
            // cannot throw. Folder names come off disk, so this is reachable.
            $hue = ($hue * 31 + (int) mb_ord($character)) % 360;
        }

        return $hue;
    }

    /** The single uppercase character shown on a tile. */
    public static function initial(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($trimmed, 0, 1));
    }
}
