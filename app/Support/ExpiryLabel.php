<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

final readonly class ExpiryLabel
{
    /** How a share link lifetime reads, and whether it has run out. */
    private function __construct(
        public string $text,
        /** A design token name: success | warning | danger. */
        public string $tone,
        public bool $dead,
    ) {}

    public static function for(?DateTimeInterface $expiresAt, ?DateTimeInterface $revokedAt = null): self
    {
        // Revoked outranks expired. Both are dead, but the words are not
        // interchangeable: one was a decision, the other was time passing.
        if ($revokedAt !== null) {
            return new self(__('Revoked'), 'danger', true);
        }

        if ($expiresAt === null) {
            return new self(__('Expired'), 'danger', true);
        }

        $expires = Carbon::instance($expiresAt);

        if ($expires->isPast()) {
            return new self(__('Expired'), 'danger', true);
        }

        $seconds = (int) max(0, $expires->diffInSeconds(Carbon::now(), absolute: true));

        return new self(
            text: __('in :remaining', ['remaining' => self::remaining($seconds)]),
            // Amber under an hour, per the design - a link about to die should not look
            // the same as one with six days left.
            tone: $seconds < (int) config('minizo.shares.warning_threshold', 3600) ? 'warning' : 'success',
            dead: false,
        );
    }

    /** Two units at most, largest first: "2d 3h", "21h 40m", "43m", "12s". */
    private static function remaining(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
        }

        if ($hours > 0) {
            return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
        }

        if ($minutes > 0) {
            return "{$minutes}m";
        }

        // Under a minute. Seconds rather than "0m", which reads as already gone.
        return "{$seconds}s";
    }

    /** The public share page's line: "Expires in 24 hours". */
    public static function humanFor(?DateTimeInterface $expiresAt): string
    {
        if ($expiresAt === null) {
            return __('Expired');
        }

        $expires = Carbon::instance($expiresAt);

        return $expires->isPast()
            ? __('Expired')
            : $expires->diffForHumans(Carbon::now(), syntax: Carbon::DIFF_ABSOLUTE, parts: 1);
    }
}
