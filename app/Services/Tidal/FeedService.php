<?php

namespace App\Services\Tidal;

use App\Exceptions\TidalException;
use App\Jobs\SyncArtistReleasesJob;
use App\Models\Artist;
use App\Models\ArtistFollow;
use App\Models\ArtistRelease;
use App\Models\User;
use App\Support\TidalArtist;
use App\Support\TidalRelease;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class FeedService
{
    /** Follows artists and keeps their releases in step with Tidal. */
    public function __construct(
        private TidalCatalogue $catalogue,
    ) {}

    /** Whether Tidal credentials are set, and so whether the Feed works. */
    public function configured(): bool
    {
        return $this->catalogue->configured();
    }

    /**
     * Artist search, rate-limited per user.
     *
     * @return array<int, TidalArtist>
     *
     * @throws TidalException
     */
    public function search(User $user, string $query): array
    {
        $key = 'tidal-search:'.$user->getKey();
        $limit = (int) config('minizo.feed.user_search_rate_limit', 20);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new TidalException(__('Slow down — too many searches. Try again in a minute.'));
        }

        RateLimiter::hit($key, 60);

        return $this->catalogue->searchArtists($query);
    }

    /** Follow an artist, creating the local row if this is the first time anyone has. */
    /**
     * Follow by provider id, resolving the artist from Tidal rather than from the caller.
     *
     * The Feed screen holds its search results in a public Livewire property, so the name
     * and image in them are whatever the browser last sent back. Artist::fromTidal
     * OVERWRITES an existing row on a provider_id match, which would let anyone rewrite
     * the name of an artist for every user who follows them. Re-fetching means the client
     * chooses WHICH artist, and Tidal decides what that artist is.
     *
     * @throws TidalException when Tidal does not know the id
     */
    public function followById(User $user, string $providerId): Artist
    {
        $artist = $this->catalogue->artist($providerId);

        if ($artist === null) {
            throw new TidalException(__('That artist could not be found on Tidal.'));
        }

        return $this->follow($user, $artist);
    }

    public function follow(User $user, TidalArtist $artist): Artist
    {
        $model = Artist::fromTidal($artist);

        // Idempotent: syncWithoutDetaching leaves an existing follow alone rather than
        // resetting its last_viewed_at, so a double-clicked Follow button does not silently
        // mark everything unread again.
        $user->followedArtists()->syncWithoutDetaching([$model->getKey()]);

        // Only when there is nothing stored yet. A second person following someone already
        // in the feed should not trigger a refetch of releases we already have.
        if ($model->releases()->doesntExist()) {
            SyncArtistReleasesJob::dispatch($model);
        }

        return $model;
    }

    /** Drop one user follow, leaving the artist and its releases alone. */
    public function unfollow(User $user, Artist $artist): void
    {
        $user->followedArtists()->detach($artist->getKey());

        // The artist row and its releases stay: someone else may follow them, and
        // scopeDueForSync already skips artists nobody follows.
    }

    /**
     * Import what Tidal currently lists for an artist.
     *
     * @return int the number of releases seen for the first time
     *
     * @throws TidalException
     */
    public function importReleases(Artist $artist): int
    {
        // Refresh the artist first: a Tidal image URL is a signed CDN link that expires,
        // and follow() is the only other place one is written. A failure here is ignored;
        // a missing picture falls back to the generated tile.
        $fresh = $this->catalogue->artist($artist->provider_id);

        if ($fresh !== null) {
            Artist::fromTidal($fresh);
            $artist->refresh();
        }

        $releases = $this->catalogue->releasesFor($artist->provider_id);

        $imported = 0;

        foreach ($releases as $release) {
            // The backfill window is applied here, not in the query: following an artist
            // with a long career would otherwise import everything and flag it all new.
            if (! $release->isWithinBackfillWindow()) {
                continue;
            }

            if ($this->store($artist, $release)) {
                $imported++;
            }
        }

        // Stamped even when nothing was imported: the point is "we asked recently", so a
        // quiet artist is not re-queried every few minutes.
        $artist->forceFill(['last_synced_at' => now()])->save();

        if ($imported > 0) {
            Log::info('Imported new releases', [
                'artist' => $artist->name,
                'count' => $imported,
            ]);
        }

        return $imported;
    }

    /**
     * @return bool whether this release had not been seen before
     */
    private function store(Artist $artist, TidalRelease $release): bool
    {
        $existing = ArtistRelease::query()
            ->where('artist_id', $artist->getKey())
            ->where('provider_id', $release->providerId)
            ->first();

        if ($existing !== null) {
            // Updated but not re-stamped: first_seen_at is what "new" reads, so a
            // corrected title would otherwise push an old release back to the top.
            $existing->forceFill($release->toAttributes())->save();

            return false;
        }

        $model = new ArtistRelease;

        $model->forceFill([
            'artist_id' => $artist->getKey(),
            ...$release->toAttributes(),
            'first_seen_at' => now(),
        ])->save();

        return true;
    }

    /**
     * The Feed screen's data: each followed artist with their recent releases.
     *
     * @return Collection<int, Artist>
     */
    public function feedFor(User $user): Collection
    {
        $perArtist = (int) config('minizo.feed.releases_per_artist', 6);

        return $user->followedArtists()
            ->with(['releases' => fn ($query) => $query->newestFirst()->limit($perArtist)])
            ->orderBy('name')
            ->get();
    }

    /**
     * Mark this user's feed as seen, and report what WAS new before doing so.
     *
     * Pass $feed when the caller has already loaded it. The Feed screen does, because
     * otherwise the page runs the whole two-query feed twice: once here from mount(),
     * once again from the computed property that renders it.
     *
     * @param  Collection<int, Artist>|null  $feed
     * @return array<int, int> release ids that were new
     */
    public function markViewed(User $user, ?Collection $feed = null): array
    {
        $newIds = [];

        foreach ($feed ?? $this->feedFor($user) as $artist) {
            /** @var ArtistFollow|null $pivot */
            $pivot = $artist->getRelation('pivot');
            $lastViewed = $pivot?->last_viewed_at;

            foreach ($artist->releases as $release) {
                if ($release->isNewFor($lastViewed)) {
                    $newIds[] = $release->getKey();
                }
            }
        }

        DB::table('artist_follows')
            ->where('user_id', $user->getKey())
            ->update(['last_viewed_at' => now(), 'updated_at' => now()]);

        return $newIds;
    }

    /**
     * Queue refreshes for artists whose releases have gone stale.
     *
     * @return int jobs queued
     */
    public function queueStaleSyncs(): int
    {
        $artists = Artist::query()
            ->dueForSync()
            ->limit((int) config('minizo.feed.sync_batch', 10))
            ->get();

        foreach ($artists as $artist) {
            SyncArtistReleasesJob::dispatch($artist);
        }

        return $artists->count();
    }
}
