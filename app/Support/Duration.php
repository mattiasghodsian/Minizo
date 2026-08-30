<?php

namespace App\Support;

final class Duration
{
    /** Format seconds as "m:ss", or null when there is no usable length. */
    public static function clock(int|float|null $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $seconds = (int) round($seconds);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** Format milliseconds as "m:ss", or null when there is no usable length. */
    public static function clockFromMs(int|float|null $milliseconds): ?string
    {
        return self::clock($milliseconds === null ? null : $milliseconds / 1000);
    }
}
