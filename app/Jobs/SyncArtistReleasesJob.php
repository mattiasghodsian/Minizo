<?php

namespace App\Jobs;

use App\Exceptions\TidalException;
use App\Models\Artist;
use App\Services\Tidal\FeedService;
use DateTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncArtistReleasesJob implements ShouldQueue
{
    use Queueable;

    /** Refreshes one artist and imports any releases not seen before. */
    public function __construct(
        public Artist $artist,
    ) {
        $this->onQueue((string) config('minizo.feed.queue', 'default'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            // Instance-wide budget, keyed on the limiter rather than the artist: the
            // limit protects Tidal's API, which does not care whose request it was.
            new RateLimited('tidal'),

            // One sync per artist at a time. Released after 10s rather than dropped, so
            // a follow that coincides with a scheduled refresh still runs.
            (new WithoutOverlapping((string) $this->artist->getKey()))
                ->releaseAfter(10)
                ->expireAfter(300),
        ];
    }

    /** Stop retrying after this long. */
    public function retryUntil(): DateTime
    {
        return now()->addHour()->toDateTime();
    }

    /** Run the sync, within the instance-wide Tidal request budget. */
    public function handle(FeedService $feed): void
    {
        // The row may be gone: an artist nobody follows any more, or a deleted account that
        // cascaded. Not an error.
        if (! $this->artist->exists) {
            return;
        }

        try {
            $feed->importReleases($this->artist);
        } catch (TidalException $e) {
            // Not re-thrown: missing credentials will not fix themselves on retry, and a
            // failed_jobs row per artist per attempt buries the one useful log line.
            Log::warning('Artist release sync could not run', [
                'artist' => $this->artist->name,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /** Record why a sync gave up after its last attempt. */
    public function failed(?Throwable $exception): void
    {
        Log::error('Artist release sync failed', [
            'artist_id' => $this->artist->getKey(),
            'error' => $exception?->getMessage(),
        ]);
    }
}
