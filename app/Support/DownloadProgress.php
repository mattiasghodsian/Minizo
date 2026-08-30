<?php

namespace App\Support;

final readonly class DownloadProgress
{
    /** One progress report parsed out of yt-dlp output. */
    private function __construct(
        public ?string $target,
        public int $percent,
        public ?string $size,
        public ?string $speed,
        public ?string $eta,
    ) {}

    /** Build from youtube-dl-php's onProgress arguments, in their order. */
    public static function fromCallback(
        ?string $target,
        string $percentage,
        ?string $size = null,
        ?string $speed = null,
        ?string $eta = null,
    ): self {
        return new self(
            target: $target,
            percent: self::parsePercent($percentage),
            size: self::clean($size),
            speed: self::clean($speed),
            eta: self::clean($eta),
        );
    }

    /** "43.7%" => 44. Clamped, because a mid-postprocessing line can report >100. */
    private static function parsePercent(string $percentage): int
    {
        return max(0, min(100, (int) round((float) rtrim(trim($percentage), '%'))));
    }

    /** yt-dlp prints literal "Unknown speed" / "Unknown ETA" placeholders rather than omitting the field, and showing those in the UI is worse than showing nothing. */
    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, 'Unknown')) {
            return null;
        }

        return mb_substr($value, 0, 32);
    }
}
