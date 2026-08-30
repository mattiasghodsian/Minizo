<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

enum ShareExpiry: int
{
    case OneHour = 3600;
    case SixHours = 21600;
    case OneDay = 86400;
    case ThreeDays = 259200;
    case OneWeek = 604800;

    /** How the lifetime reads in the Share modal. */
    public function label(): string
    {
        return match ($this) {
            self::OneHour => '1 hour',
            self::SixHours => '6 hours',
            self::OneDay => '24 hours',
            self::ThreeDays => '72 hours',
            self::OneWeek => '7 days',
        };
    }

    /** The absolute expiry instant, measured from now unless a base is given. */
    public function toDate(?DateTimeImmutable $from = null): CarbonImmutable
    {
        $base = $from !== null
            ? CarbonImmutable::instance($from)
            : CarbonImmutable::now();

        return $base->addSeconds($this->value);
    }

    /** The lifetime in seconds, for stamping expires_at. */
    public function seconds(): int
    {
        return $this->value;
    }

    /** The option pre-selected in the Share modal. */
    public static function default(): self
    {
        return self::OneDay;
    }

    /**
     * Value => label, for a select.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
