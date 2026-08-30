<?php

namespace App\Enums;

enum MusicBrainzState: string
{
    case Identified = 'identified';
    case ReleaseOnly = 'release-only';
    case Missing = 'missing';

    /** Derive the state from the two identifiers a tagged file carries. */
    public static function fromIds(?string $trackId, ?string $albumId): self
    {
        return match (true) {
            filled($trackId) => self::Identified,
            filled($albumId) => self::ReleaseOnly,
            default => self::Missing,
        };
    }

    /** The mark shown in the Files listing's MB column. */
    public function glyph(): string
    {
        return $this === self::Missing ? '✗' : '✓';
    }

    /** The design token the mark renders in. */
    public function tone(): string
    {
        return match ($this) {
            self::Identified => 'text-success',
            self::ReleaseOnly => 'text-warning',
            self::Missing => 'text-ink-faint',
        };
    }

    /** Wording for the cell's tooltip and its screen-reader label. */
    public function label(): string
    {
        return match ($this) {
            self::Identified => __('Identified on MusicBrainz'),
            self::ReleaseOnly => __('Release identified, but this recording is not'),
            self::Missing => __('No MusicBrainz metadata'),
        };
    }
}
