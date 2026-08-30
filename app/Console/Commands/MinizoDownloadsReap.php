<?php

namespace App\Console\Commands;

use App\Enums\DownloadStatus;
use App\Models\DownloadJob;
use Illuminate\Console\Command;

class MinizoDownloadsReap extends Command
{
    protected $signature = 'minizo:downloads:reap';

    protected $description = 'Fail downloads whose progress has gone stale, and prune old download history';

    /** Fail downloads whose worker went away, then prune old history. */
    public function handle(): int
    {
        $this->reapStalled();
        $this->pruneHistory();

        return self::SUCCESS;
    }

    /** Fail running jobs that have not reported progress in too long. */
    private function reapStalled(): void
    {
        $timeout = (int) config('minizo.downloads.stall_timeout', 900);
        $cutoff = now()->subSeconds($timeout);

        // Only running rows: a queued one has no progress to go stale, it is waiting
        // for a worker.
        $stalled = DownloadJob::query()
            ->where('status', DownloadStatus::Running)
            ->where(fn ($query) => $query
                ->where('progress_updated_at', '<', $cutoff)
                // A running row that never reported at all. Judged on created_at
                // instead, because a NULL never satisfies a "<" comparison in SQL -
                // so without this branch the worst case would be the one row the
                // reaper could never see.
                ->orWhere(fn ($query) => $query
                    ->whereNull('progress_updated_at')
                    ->where('created_at', '<', $cutoff)))
            ->get();

        foreach ($stalled as $job) {
            $job->markFailed(__('The download stalled and was stopped after :minutes minutes without progress.', [
                'minutes' => (int) ceil($timeout / 60),
            ]));

            $this->components->warn("Stalled: #{$job->getKey()} {$job->url}");
        }

        $this->components->info($stalled->isEmpty()
            ? 'No stalled downloads.'
            : $stalled->count().' stalled download(s) marked failed.');
    }

    /** Delete finished rows past the retention window. */
    private function pruneHistory(): void
    {
        $days = (int) config('minizo.downloads.history_days', 30);

        // Logged rather than deleted quietly, so the retention promise made on the
        // Download screen ("recent activity") is auditable after the fact.
        $deleted = DownloadJob::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', now()->subDays($days))
            ->delete();

        $this->components->info($deleted === 0
            ? 'No download history to prune.'
            : "Pruned {$deleted} download row(s) older than {$days} days.");
    }
}
