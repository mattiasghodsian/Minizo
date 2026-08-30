<?php

namespace App\Support;

final class Lucene
{
    /** Characters Lucene treats as syntax. */
    private const SPECIAL = ['\\', '+', '-', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '/', '&', '|'];

    /** Escape a value for use as a bare term. */
    public static function escape(string $value): string
    {
        $escaped = $value;

        // Backslash first, or it would double-escape everything added after it.
        foreach (self::SPECIAL as $character) {
            $escaped = str_replace($character, '\\'.$character, $escaped);
        }

        return $escaped;
    }

    /** Wrap a value as a quoted phrase: Radiohead => "Radiohead". */
    public static function phrase(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($value === '') {
            return '';
        }

        // Inside a phrase only these two are special.
        $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"'.$value.'"';
    }

    /** field:"value", or an empty string when there is no value to search on. */
    public static function field(string $field, string $value): string
    {
        $phrase = self::phrase($value);

        return $phrase === '' ? '' : $field.':'.$phrase;
    }

    /**
     * Join non-empty clauses with AND.
     *
     * @param  array<int, string>  $clauses
     */
    public static function all(array $clauses): string
    {
        return implode(' AND ', array_filter($clauses, fn (string $clause): bool => trim($clause) !== ''));
    }
}
