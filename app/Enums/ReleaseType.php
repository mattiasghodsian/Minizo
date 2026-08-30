<?php

namespace App\Enums;

enum ReleaseType: string
{
    case Album = 'album';
    case Ep = 'ep';
    case Single = 'single';
    case Compilation = 'compilation';
    case Other = 'other';
    case Standalone = 'standalone';

    /** How the release type reads on a feed row. */
    public function label(): string
    {
        return match ($this) {
            self::Album => 'Album',
            self::Ep => 'EP',
            self::Single => 'Single',
            self::Compilation => 'Compilation',
            self::Other => 'Release',
            self::Standalone => 'Standalone',
        };
    }

    /** Ranking weight: a single tags a single track far better than a 40-track compilation does, so a smaller, more specific release wins. */
    public function weight(): int
    {
        return match ($this) {
            self::Single => 5,
            self::Ep => 4,
            self::Album => 3,
            self::Standalone => 2,
            self::Other => 1,
            self::Compilation => 0,
        };
    }

    /** Whether picking this candidate needs a track chosen from a listing. */
    public function needsTrackSelection(): bool
    {
        return $this !== self::Standalone;
    }

    /** Map Tidal's album type. */
    public static function fromTidal(?string $type): self
    {
        return match (strtoupper(trim((string) $type))) {
            'ALBUM' => self::Album,
            'EP' => self::Ep,
            'SINGLE' => self::Single,
            // Tidal uses this for multi-disc/deluxe bundles on some catalogue entries.
            'EP_SINGLE', 'EPSINGLE' => self::Ep,
            'COMPILATION' => self::Compilation,
            default => self::Other,
        };
    }

    /**
     * Map a MusicBrainz release-group primary type plus its secondary types.
     *
     * @param  array<int, string>  $secondaryTypes
     */
    public static function fromMusicBrainz(?string $primaryType, array $secondaryTypes = []): self
    {
        $secondary = array_map('strtolower', $secondaryTypes);

        if (in_array('compilation', $secondary, true)) {
            return self::Compilation;
        }

        return match (strtolower((string) $primaryType)) {
            'single' => self::Single,
            'ep' => self::Ep,
            'album' => self::Album,
            default => self::Other,
        };
    }
}
