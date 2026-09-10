<?php

namespace App\Http\Controllers\Api;

use App\Models\Artist;
use App\Models\ArtistFollow;
use App\Models\ArtistRelease;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedController
{
    /**
     * The caller's followed artists and their recent releases.
     *
     * Read-only: last_viewed_at is what the Feed screen's NEW badges read, so a polling
     * client must not clear it. Serves stored rows only — no Tidal call.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $feed = $request->user()->feed();

        $artists = $feed->map(fn (Artist $artist) => $this->artist($artist))->values();

        return response()->json([
            'data' => $artists,
            'meta' => [
                'artists' => $artists->count(),
                'releases' => $artists->sum(fn (array $artist) => count($artist['releases'])),
                'new_releases' => $artists->sum(
                    fn (array $artist) => count(array_filter(
                        $artist['releases'],
                        fn (array $release) => $release['is_new'],
                    )),
                ),
                'releases_per_artist' => (int) config('minizo.feed.releases_per_artist', 6),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function artist(Artist $artist): array
    {
        /** @var ArtistFollow|null $pivot */
        $pivot = $artist->getRelation('pivot');
        $lastViewed = $pivot?->last_viewed_at;

        return [
            'id' => $artist->getKey(),
            'name' => $artist->name,
            'provider' => $artist->provider,
            'provider_id' => $artist->provider_id,
            'image_url' => $artist->image_url,
            'tidal_url' => $artist->tidalUrl(),
            'last_viewed_at' => $lastViewed?->toIso8601String(),
            'releases' => $artist->releases
                ->map(fn (ArtistRelease $release) => $this->release($release, $artist, $lastViewed))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function release(ArtistRelease $release, Artist $artist, ?DateTimeInterface $lastViewed): array
    {
        return [
            'id' => $release->getKey(),
            'title' => $release->title,
            'artist' => $artist->name,
            'artist_id' => $artist->getKey(),
            'type' => $release->release_type?->value,
            'type_label' => $release->release_type?->label(),
            'released_on' => $release->released_on?->format('Y-m-d'),
            'year' => $release->yearLabel(),
            'cover_url' => $release->cover_url,
            // link is nullable, so fall back to the id-built album URL rather than null.
            'tidal_url' => $release->link ?: 'https://tidal.com/browse/album/'.$release->provider_id,
            'youtube_music_url' => $release->youtubeMusicUrl($artist->name),
            'first_seen_at' => $release->first_seen_at->toIso8601String(),
            'is_new' => $release->isNewFor($lastViewed),
        ];
    }
}
