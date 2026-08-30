<?php

namespace App\Services\MusicBrainz;

use App\Exceptions\MetadataException;
use App\Models\User;
use App\Support\ReleaseCandidate;
use App\Support\TrackCandidate;
use App\Support\TrackMetadata;
use Illuminate\Support\Facades\RateLimiter;

class MetadataLookup
{
    /** The editor entry point: search MusicBrainz and map what comes back. */
    public function __construct(
        private MusicBrainzSearch $search,
        private ReleaseMapper $releases,
        private RecordingMapper $recordings,
        private CoverArtArchive $coverArt,
    ) {}

    /**
     * Step 1.
     *
     * @return array<int, ReleaseCandidate>
     *
     * @throws MetadataException when the user is searching too fast
     */
    public function candidates(User $user, string $artist, string $title): array
    {
        $limit = (int) config('minizo.musicbrainz.user_rate_limit', 10);

        if (RateLimiter::tooManyAttempts($this->limiterKey($user), $limit)) {
            throw MetadataException::searchThrottled();
        }

        RateLimiter::hit($this->limiterKey($user), 60);

        return $this->search->search($artist, $title);
    }

    /**
     * Step 2: the track listing for a release the user picked.
     *
     * @return array<int, TrackCandidate>
     *
     * @throws MetadataException
     */
    public function tracks(string $releaseId, string $searchTitle = ''): array
    {
        $release = $this->search->release($releaseId) ?? throw MetadataException::lookupFailed();

        return $this->releases->tracks($release, $searchTitle);
    }

    /**
     * Step 3, release path.
     *
     * @throws MetadataException
     */
    public function trackMetadata(string $releaseId, int $mediaPosition, int $trackIndex): TrackMetadata
    {
        $release = $this->search->release($releaseId) ?? throw MetadataException::lookupFailed();

        // Fetched here rather than in the mapper: this is a second network call, and the
        // mapper stays a pure function over a document.
        return $this->releases->metadata(
            release: $release,
            mediaPosition: $mediaPosition,
            trackIndex: $trackIndex,
            coverArtUrl: $this->coverArt->frontCoverUrl($releaseId),
        );
    }

    /**
     * Step 3, standalone path - no step 2, and no cover art to fetch.
     *
     * @throws MetadataException
     */
    public function standaloneMetadata(string $recordingId): TrackMetadata
    {
        $recording = $this->search->recording($recordingId) ?? throw MetadataException::lookupFailed();

        return $this->recordings->metadata($recording);
    }

    /** Remaining searches this minute - shown to the user rather than only enforced, so hitting the limit is not a surprise. */
    public function searchesRemaining(User $user): int
    {
        return RateLimiter::remaining(
            $this->limiterKey($user),
            (int) config('minizo.musicbrainz.user_rate_limit', 10),
        );
    }

    /** The per-user rate limiter key. */
    private function limiterKey(User $user): string
    {
        return 'musicbrainz-search:'.$user->getKey();
    }
}
