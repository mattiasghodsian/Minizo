<?php

namespace App\Services\MusicBrainz;

use App\Enums\ReleaseType;
use App\Support\Lucene;
use App\Support\ReleaseCandidate;

class MusicBrainzSearch
{
    /** Runs the release and recording searches behind step one of the editor. */
    public function __construct(
        private MusicBrainzClient $client,
        private ReleaseCandidateRanker $ranker,
    ) {}

    /**
     * @return array<int, ReleaseCandidate>
     */
    public function search(string $artist, string $title): array
    {
        $artist = trim($artist);
        $title = trim($title);

        if ($title === '' && $artist === '') {
            return [];
        }

        $candidates = [
            ...$this->releasePass($artist, $title),
            ...$this->recordingPass($artist, $title),
        ];

        // Only probe for standalones when we know the artist. Without one the query
        // degenerates to "every recording with no release", which is millions of rows
        // of noise.
        if ($artist !== '') {
            $candidates = [...$candidates, ...$this->standalonePass($artist, $title)];
        }

        if ($candidates === []) {
            $candidates = $this->dismaxPass(trim($artist.' '.$title));
        }

        $ranked = $this->ranker->rank($this->ranker->dedupe($candidates));

        return array_slice($ranked, 0, (int) config('minizo.musicbrainz.max_candidates', 40));
    }

    /**
     * Load one release by MBID, with everything step 2 and step 3 need.
     *
     * @return array<string, mixed>|null
     */
    public function release(string $releaseId): ?array
    {
        return $this->client->get('release/'.$releaseId, [
            // One request for the whole document; splitting these would cost a second
            // each against the rate limit.
            'inc' => implode('+', [
                'artists', 'artist-credits', 'recordings', 'labels',
                'genres', 'media', 'isrcs', 'url-rels', 'release-groups',
            ]),
        ]);
    }

    /**
     * Load one recording by MBID - the standalone path, where there is no release.
     *
     * @return array<string, mixed>|null
     */
    public function recording(string $recordingId): ?array
    {
        return $this->client->get('recording/'.$recordingId, [
            'inc' => implode('+', ['artists', 'artist-credits', 'isrcs', 'genres', 'url-rels']),
        ]);
    }

    // ------------------------------------------------------------------- passes

    /**
     * @return array<int, ReleaseCandidate>
     */
    private function releasePass(string $artist, string $title): array
    {
        $query = Lucene::all([
            // `recording:` IS a valid field on release search - it matches releases
            // containing a recording of that name. `track:` is not.
            Lucene::field('recording', $title),
            Lucene::field('artist', $artist),
        ]);

        if ($query === '') {
            return [];
        }

        $response = $this->client->get('release', [
            'query' => $query,
            'limit' => (int) config('minizo.musicbrainz.search_limit', 25),
        ]);

        return $this->mapReleases($response['releases'] ?? []);
    }

    /**
     * @return array<int, ReleaseCandidate>
     */
    private function recordingPass(string $artist, string $title): array
    {
        $query = Lucene::all([
            Lucene::field('recording', $title),
            Lucene::field('artist', $artist),
        ]);

        if ($query === '') {
            return [];
        }

        $response = $this->client->get('recording', [
            'query' => $query,
            'limit' => (int) config('minizo.musicbrainz.recording_search_limit', 100),
        ]);

        $candidates = [];

        foreach ($response['recordings'] ?? [] as $recording) {
            $releases = $recording['releases'] ?? [];

            // An empty or absent `releases` array means a standalone recording.
            if ($releases === []) {
                $candidates[] = $this->standaloneCandidate($recording);

                continue;
            }

            foreach ($releases as $release) {
                // The score belongs to the recording, not to each of its releases,
                // so it is carried down explicitly.
                $candidates[] = $this->mapRelease($release, (int) ($recording['score'] ?? 0));
            }
        }

        return $candidates;
    }

    /**
     * Recordings with no release attached.
     *
     * @return array<int, ReleaseCandidate>
     */
    private function standalonePass(string $artist, string $title): array
    {
        $clauses = [Lucene::field('artist', $artist), '-reid:*'];

        // Titles narrow it usefully but must not be required: the standalone we want
        // is often titled slightly differently from the file.
        if ($title !== '') {
            array_splice($clauses, 1, 0, [Lucene::field('recording', $title)]);
        }

        $response = $this->client->get('recording', [
            'query' => Lucene::all($clauses),
            'limit' => (int) config('minizo.musicbrainz.search_limit', 25),
        ]);

        $candidates = [];

        foreach ($response['recordings'] ?? [] as $recording) {
            // Trust but verify: the filter is a query, not a guarantee.
            if (($recording['releases'] ?? []) !== []) {
                continue;
            }

            $candidates[] = $this->standaloneCandidate($recording);
        }

        return $candidates;
    }

    /**
     * The last resort, on the raw user string.
     *
     * @return array<int, ReleaseCandidate>
     */
    private function dismaxPass(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $response = $this->client->get('release', [
            'query' => $raw,
            'dismax' => 'true',
            'limit' => (int) config('minizo.musicbrainz.search_limit', 25),
        ]);

        return $this->mapReleases($response['releases'] ?? []);
    }

    // ------------------------------------------------------------------ mapping

    /**
     * @param  array<int, array<string, mixed>>  $releases
     * @return array<int, ReleaseCandidate>
     */
    private function mapReleases(array $releases): array
    {
        return array_map(fn (array $release): ReleaseCandidate => $this->mapRelease($release), $releases);
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function mapRelease(array $release, ?int $score = null): ReleaseCandidate
    {
        $group = $release['release-group'] ?? [];

        return new ReleaseCandidate(
            id: (string) ($release['id'] ?? ''),
            title: (string) ($release['title'] ?? ''),
            artist: self::creditedName($release['artist-credit'] ?? []),
            type: ReleaseType::fromMusicBrainz(
                $group['primary-type'] ?? null,
                $group['secondary-types'] ?? [],
            ),
            date: $release['date'] ?? null,
            country: $release['country'] ?? null,
            status: $release['status'] ?? null,
            // track-count is absent on the releases embedded in a recording result;
            // 1 is the safer default than 0, which would sort everything above albums.
            trackCount: (int) ($release['track-count'] ?? 1),
            score: $score ?? (int) ($release['score'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $recording
     */
    private function standaloneCandidate(array $recording): ReleaseCandidate
    {
        return new ReleaseCandidate(
            id: (string) ($recording['id'] ?? ''),
            // The recording title is all there is - there is no release to name.
            title: (string) ($recording['title'] ?? ''),
            artist: self::creditedName($recording['artist-credit'] ?? []),
            type: ReleaseType::Standalone,
            date: $recording['first-release-date'] ?? null,
            score: (int) ($recording['score'] ?? 0),
            lengthMs: isset($recording['length']) ? (int) $recording['length'] : null,
        );
    }

    /**
     * Flatten an artist credit into the string MusicBrainz would print.
     *
     * @param  array<int, array<string, mixed>>  $credits
     */
    public static function creditedName(array $credits): string
    {
        $name = '';

        foreach ($credits as $credit) {
            $name .= ($credit['name'] ?? $credit['artist']['name'] ?? '').($credit['joinphrase'] ?? '');
        }

        return trim($name);
    }
}
