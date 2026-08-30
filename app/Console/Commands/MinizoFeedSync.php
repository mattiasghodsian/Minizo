<?php

namespace App\Console\Commands;

use App\Jobs\SyncArtistReleasesJob;
use App\Models\Artist;
use App\Services\Tidal\FeedService;
use Illuminate\Console\Command;

class MinizoFeedSync extends Command
{
    protected $signature = 'minizo:feed:sync
        {--all : queue every followed artist, ignoring how recently they were synced}';

    protected $description = 'Queue release refreshes for followed artists';

    /** Queue a release sync for every followed artist, or only those due. */
    public function handle(FeedService $feed): int
    {
        if (! $feed->configured()) {
            // Not a failure: an instance with no Tidal credentials is a perfectly valid
            // install, and a scheduled command that reported failure every hour would train
            // people to ignore the scheduler's output.
            $this->components->info('Tidal is not configured — nothing to sync.');

            return self::SUCCESS;
        }

        if ($this->option('all')) {
            $artists = Artist::query()->whereHas('followers')->get();

            foreach ($artists as $artist) {
                SyncArtistReleasesJob::dispatch($artist);
            }

            $this->components->info("Queued {$artists->count()} artist(s) — all followed artists.");

            return self::SUCCESS;
        }

        $queued = $feed->queueStaleSyncs();

        $this->components->info($queued === 0
            ? 'Every followed artist is up to date.'
            : "Queued {$queued} artist(s) due a refresh.");

        return self::SUCCESS;
    }
}
