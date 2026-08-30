<?php

namespace App\Enums;

enum AudioFormat: string
{
    case Flac = 'flac';

    /** The file extension, without a leading dot. */
    public function extension(): string
    {
        return match ($this) {
            self::Flac => 'flac',
        };
    }

    /** Human label for selects and badges. */
    public function label(): string
    {
        return match ($this) {
            self::Flac => 'FLAC',
        };
    }

    /** Whether the format keeps every sample, which decides the quality flag. */
    public function isLossless(): bool
    {
        return match ($this) {
            self::Flac => true,
        };
    }

    /** The default format for new downloads. */
    public static function default(): self
    {
        return self::Flac;
    }

    /**
     * Extensions the library treats as playable audio, lowercase.
     *
     * @return array<int, string>
     */
    public static function extensions(): array
    {
        return array_map(fn (self $format): string => $format->extension(), self::cases());
    }

    /** Resolve from a filename. Null when the extension is not one we handle. */
    public static function fromFilename(string $filename): ?self
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::tryFrom($extension);
    }
}
