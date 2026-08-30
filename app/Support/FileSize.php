<?php

namespace App\Support;

final class FileSize
{
    private const MEGABYTE = 1_048_576;

    private const GIGABYTE = 1_073_741_824;

    /** Format bytes as "42.10 MB", switching to GB once the value reaches one. */
    public static function label(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 MB';
        }

        if ($bytes >= self::GIGABYTE) {
            return number_format($bytes / self::GIGABYTE, 2).' GB';
        }

        return number_format($bytes / self::MEGABYTE, 2).' MB';
    }
}
