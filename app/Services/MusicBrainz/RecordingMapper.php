<?php

namespace App\Services\MusicBrainz;

use App\Support\Scalar;
use App\Support\TrackMetadata;
use Illuminate\Support\Arr;

class RecordingMapper
{
    /**
     * @param  array<string, mixed>  $recording
     */
    public function metadata(array $recording): TrackMetadata
    {
        return new TrackMetadata(
            title: Scalar::stringOrNull($recording['title'] ?? null),
            artist: Scalar::stringOrNull(MusicBrainzSearch::creditedName($recording['artist-credit'] ?? [])),

            // No album, and not the title as a stand-in: ALBUM == TITLE makes every
            // standalone look like a single-track album in a player's library view.
            album: null,

            // first-release-date is the closest thing a recording has to a year. It is
            // frequently absent, which is fine - the design shows the cell blank.
            year: self::year($recording['first-release-date'] ?? null),

            lengthMs: is_numeric($recording['length'] ?? null) ? (int) $recording['length'] : null,
            isrc: Scalar::stringOrNull(Arr::get($recording, 'isrcs.0')),
            genres: self::genres($recording),
            releaseId: null,
            recordingId: Scalar::stringOrNull($recording['id'] ?? null),
            link: self::firstUrl($recording['relations'] ?? []),
            coverArtUrl: null,
            standalone: true,
        );
    }

    /**
     * @param  array<string, mixed>  $recording
     * @return array<int, string>
     */
    private static function genres(array $recording): array
    {
        $genres = $recording['genres'] ?? [];

        if (! is_array($genres)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $genre): string => (string) ($genre['name'] ?? ''),
            $genres,
        )));
    }

    /**
     * @param  array<int, array<string, mixed>>  $relations
     */
    private static function firstUrl(array $relations): ?string
    {
        foreach ($relations as $relation) {
            $url = Arr::get($relation, 'url.resource');

            if (filled($url)) {
                return (string) $url;
            }
        }

        return null;
    }

    /** The year out of a MusicBrainz date, which may be partial. */
    private static function year(mixed $date): ?string
    {
        return preg_match('/^(\d{4})/', trim((string) $date), $match) === 1 ? $match[1] : null;
    }
}
