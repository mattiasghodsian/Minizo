<?php

namespace App\Support;

final class Sharing
{
    /** Overrides the stored value for the duration of a test. */
    private static ?bool $override = null;

    /** Whether public sharing is switched on for this instance. */
    public static function enabled(): bool
    {
        if (self::$override !== null) {
            return self::$override;
        }

        return Settings::bool(
            Settings::SHARING_ENABLED,
            // The config value is the default for an instance that has never touched
            // the toggle, not the source of truth once it has.
            (bool) config('minizo.shares.enabled', true),
        );
    }

    /** Switch public sharing on or off. */
    public static function set(bool $enabled): void
    {
        Settings::put(Settings::SHARING_ENABLED, $enabled);
    }

    /** Override the switch for the rest of the test. */
    public static function fake(bool $enabled): void
    {
        self::$override = $enabled;
    }

    /** Drop a test override. */
    public static function clearFake(): void
    {
        self::$override = null;
    }
}
