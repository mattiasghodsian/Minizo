<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

final class PageHeading
{
    /**
     * @return array{0: ?string, 1: ?string} [heading, subheading]
     */
    public static function current(): array
    {
        $name = Route::current()?->getName() ?? '';

        // Indexed directly rather than via config('minizo.pages.'.$name): route names
        // contain dots, and config() reads a dot as nesting.
        $pages = (array) config('minizo.pages', []);
        $mapped = (array) ($pages[$name] ?? [null, null]);

        return [
            self::interpolate($mapped[0] ?? null),
            self::interpolate($mapped[1] ?? null),
        ];
    }

    /** The heading for the current route, or null when it has none. */
    public static function heading(): ?string
    {
        return self::current()[0];
    }

    /** Fill {param} placeholders from the current route. */
    private static function interpolate(?string $text): ?string
    {
        if ($text === null || ! str_contains($text, '{')) {
            return $text;
        }

        foreach (Route::current()?->parameters() ?? [] as $key => $value) {
            if (is_scalar($value)) {
                $text = str_replace('{'.$key.'}', (string) $value, $text);
            }
        }

        // Drop any placeholder with no matching parameter, so a missing value shows
        // as empty rather than a literal "{directory}".
        return trim((string) preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $text));
    }
}
