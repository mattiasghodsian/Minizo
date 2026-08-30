<?php

namespace App\Services\MusicBrainz;

use App\Support\Scalar;
use App\Support\TrackCandidate;
use App\Support\TrackMetadata;
use App\Support\TrackTitleNormaliser;
use Illuminate\Support\Arr;

class ReleaseMapper
{
    /**
     * Every track on the release, flattened across discs.
     *
     * @param  array<string, mixed>  $release
     * @param  string  $searchTitle  What the user was looking for; drives the match bar
     *                               and the "Best Match" badge.
     * @return array<int, TrackCandidate>
     */
    public function tracks(array $release, string $searchTitle = ''): array
    {
        $rows = [];

        foreach ($release['media'] ?? [] as $mediaPosition => $medium) {
            foreach ($medium['tracks'] ?? [] as $trackIndex => $track) {
                // The track title and its recording's title can differ (a release can
                // retitle a track); the printed track title is what a user recognises.
                $title = (string) ($track['title'] ?? Arr::get($track, 'recording.title', ''));

                $rows[] = [
                    'mediaPosition' => (int) $mediaPosition,
                    'trackIndex' => (int) $trackIndex,
                    'number' => (string) ($track['number'] ?? $track['position'] ?? ''),
                    'title' => $title,
                    // Track length, then the recording's - a track can carry a length
                    // the shared recording does not.
                    'lengthMs' => Scalar::intOrNull($track['length'] ?? Arr::get($track, 'recording.length')),
                    'recordingId' => (string) Arr::get($track, 'recording.id', ''),
                    'score' => $searchTitle === ''
                        ? 0.0
                        : TrackTitleNormaliser::similarity($searchTitle, $title),
                    'mediaFormat' => $medium['format'] ?? null,
                ];
            }
        }

        return $this->withBestMatch($rows);
    }

    /**
     * Flag at most one row as the best match.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, TrackCandidate>
     */
    private function withBestMatch(array $rows): array
    {
        $bestIndex = null;
        $bestScore = 0.0;

        foreach ($rows as $index => $row) {
            if ($row['score'] > $bestScore) {
                $bestScore = $row['score'];
                $bestIndex = $index;
            }
        }

        return array_map(fn (int $index): TrackCandidate => new TrackCandidate(
            mediaPosition: $rows[$index]['mediaPosition'],
            trackIndex: $rows[$index]['trackIndex'],
            number: $rows[$index]['number'],
            title: $rows[$index]['title'],
            lengthMs: $rows[$index]['lengthMs'],
            recordingId: $rows[$index]['recordingId'],
            matchScore: $rows[$index]['score'],
            isBestMatch: $index === $bestIndex,
            mediaFormat: $rows[$index]['mediaFormat'],
        ), array_keys($rows));
    }

    /**
     * The tag set for one track on a release.
     *
     * @param  array<string, mixed>  $release
     */
    public function metadata(array $release, int $mediaPosition = 0, int $trackIndex = 0, ?string $coverArtUrl = null): TrackMetadata
    {
        $medium = Arr::get($release, "media.{$mediaPosition}", []);
        $track = Arr::get($medium, "tracks.{$trackIndex}", []);

        // The indices come from the client and may point at nothing. Returning early
        // keeps isWritable() false rather than writing a blank title.
        if ($track === []) {
            return new TrackMetadata(releaseId: Scalar::stringOrNull($release['id'] ?? null));
        }

        $releaseArtist = MusicBrainzSearch::creditedName($release['artist-credit'] ?? []);

        return new TrackMetadata(
            title: Scalar::stringOrNull($track['title'] ?? Arr::get($track, 'recording.title')),

            // The track's artist credit, falling back to the release's: on a compilation
            // the release is "Various Artists" and only the track knows the performer.
            artist: Scalar::stringOrNull(
                MusicBrainzSearch::creditedName($track['artist-credit'] ?? [])
                    ?: MusicBrainzSearch::creditedName(Arr::get($track, 'recording.artist-credit', []))
                    ?: $releaseArtist
            ),
            albumArtist: Scalar::stringOrNull($releaseArtist),
            album: Scalar::stringOrNull($release['title'] ?? null),

            // MusicBrainz dates can be a year, a year-month, or a full date. Only the
            // year goes into a tag.
            year: self::year($release['date'] ?? null),

            trackNumber: Scalar::stringOrNull($track['number'] ?? $track['position'] ?? null),
            totalTracks: Scalar::intOrNull($medium['track-count'] ?? $release['track-count'] ?? null),
            lengthMs: Scalar::intOrNull($track['length'] ?? Arr::get($track, 'recording.length')),

            // First ISRC only: the tag holds one, and a recording can carry several
            // when it has been released in multiple territories.
            isrc: Scalar::stringOrNull(Arr::get($track, 'recording.isrcs.0')),

            barcode: Scalar::stringOrNull($release['barcode'] ?? null),
            label: Scalar::stringOrNull(Arr::get($release, 'label-info.0.label.name')),
            status: Scalar::stringOrNull($release['status'] ?? null),
            mediaFormat: Scalar::stringOrNull($medium['format'] ?? null),
            country: Scalar::stringOrNull($release['country'] ?? null),
            language: Scalar::stringOrNull(Arr::get($release, 'text-representation.language')),
            genres: self::genres($release, $track),
            releaseId: Scalar::stringOrNull($release['id'] ?? null),
            recordingId: Scalar::stringOrNull(Arr::get($track, 'recording.id')),
            link: self::firstUrl($release['relations'] ?? []),
            coverArtUrl: $coverArtUrl,
            standalone: false,
        );
    }

    /**
     * Genres, most specific source first.
     *
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $track
     * @return array<int, string>
     */
    private static function genres(array $release, array $track): array
    {
        $sources = [
            Arr::get($track, 'recording.genres'),
            $release['genres'] ?? null,
            Arr::get($release, 'release-group.genres'),
        ];

        foreach ($sources as $source) {
            if (! is_array($source) || $source === []) {
                continue;
            }

            // MusicBrainz orders genres by vote count already.
            $names = array_values(array_filter(array_map(
                fn (array $genre): string => (string) ($genre['name'] ?? ''),
                $source,
            )));

            if ($names !== []) {
                return $names;
            }
        }

        return [];
    }

    /**
     * The first external URL related to the release.
     *
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
        $date = trim((string) $date);

        return preg_match('/^(\d{4})/', $date, $match) === 1 ? $match[1] : null;
    }
}
