<?php

namespace App\Services\Download;

use App\Exceptions\DownloadCancelled;
use App\Models\DownloadJob;
use App\Support\DownloadProgress;
use Illuminate\Support\Facades\DB;

class ProgressWriter
{
    private float $lastWriteAt = 0.0;

    private int $lastPercent = -1;

    /** Writes yt-dlp progress onto a job row, no more often than the throttle allows. */
    private function __construct(
        private readonly DownloadJob $job,
        private readonly float $throttle,
    ) {}

    public static function for(DownloadJob $job): self
    {
        return new self($job, (float) config('minizo.downloads.progress_throttle', 1.0));
    }

    /**
     * @throws DownloadCancelled when the queue row has been asked to stop.
     */
    public function __invoke(DownloadProgress $progress): void
    {
        if (! $this->due($progress)) {
            return;
        }

        // Only on the write tick, so a cancel check costs one indexed read per second
        // per job. Read through the builder because refresh() would overwrite the
        // in-memory progress fields about to be saved.
        if ($this->cancelRequested()) {
            throw DownloadCancelled::make();
        }

        $this->lastWriteAt = microtime(true);
        $this->lastPercent = $progress->percent;

        $this->job->forceFill([
            'progress_percent' => $progress->percent,
            'speed_label' => $progress->speed,
            'eta_label' => $progress->eta,
            'size_label' => $progress->size ?? $this->job->size_label,
            'progress_updated_at' => now(),
        ])->save();
    }

    /** Whether this report is worth a database write yet. */
    private function due(DownloadProgress $progress): bool
    {
        // The first report, and completion, are never throttled away.
        if ($this->lastWriteAt === 0.0 || ($progress->percent >= 100 && $this->lastPercent < 100)) {
            return true;
        }

        return (microtime(true) - $this->lastWriteAt) >= $this->throttle;
    }

    /** Whether someone asked this download to stop since the last check. */
    private function cancelRequested(): bool
    {
        return DB::table('download_jobs')
            ->where('id', $this->job->getKey())
            ->whereNotNull('cancel_requested_at')
            ->exists();
    }
}
