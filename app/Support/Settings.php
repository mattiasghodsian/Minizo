<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class Settings
{
    public const SHARING_ENABLED = 'sharing.enabled';

    private const CACHE_KEY = 'minizo:settings';

    /**
     * Per-request memo.
     *
     * The cache store is the database by default, so every Cache::get here is a
     * query. This is read once per row-menu item while rendering the Files screen,
     * which at the default page size is several hundred reads of a value that
     * cannot change mid-request.
     *
     * @var array<string, string|null>|null
     */
    private static ?array $memo = null;

    /**
     * All settings as a key => raw-value map.
     *
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return self::$memo = $cached;
        }

        $settings = self::read();

        // No TTL: the value only changes when someone writes it, and put() below drops
        // the key. An expiry would only add a periodic query that changes nothing.
        Cache::forever(self::CACHE_KEY, $settings);

        return self::$memo = $settings;
    }

    /** Drop the per-request memo. For tests, and for anything that writes the table directly. */
    public static function forgetMemo(): void
    {
        self::$memo = null;
    }

    /** Read a stored boolean, falling back to the config default. */
    public static function bool(string $key, bool $default): bool
    {
        $value = self::all()[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        // Stored as '1'/'0'. filter_var handles the strings a hand-edited row might
        // contain ('true', 'yes') without pretending 'banana' is true.
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** Store one setting and drop the memo. */
    public static function put(string $key, string|bool|int|null $value): void
    {
        $stored = match (true) {
            is_bool($value) => $value ? '1' : '0',
            $value === null => null,
            default => (string) $value,
        };

        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $stored, 'updated_at' => now(), 'created_at' => now()],
        );

        Cache::forget(self::CACHE_KEY);

        self::$memo = null;
    }

    /**
     * @return array<string, string|null>
     */
    private static function read(): array
    {
        try {
            return DB::table('settings')->pluck('value', 'key')->all();
        } catch (Throwable) {
            // Swallowed: this is read while rendering almost anything, and the table is
            // legitimately absent during migrate on a fresh install. An empty map falls
            // back to the config defaults.
            return [];
        }
    }
}
