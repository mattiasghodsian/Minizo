<?php

namespace App\Support;

final class Scalar
{
    /** Return a trimmed string, or null for anything empty or not scalar. */
    public static function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Return an integer, or null when the value is not numeric. */
    public static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
