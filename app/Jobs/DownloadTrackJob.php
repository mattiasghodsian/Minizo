<?php

namespace App\Jobs;

use App\Enums\DownloadStatus;
use App\Exceptions\DownloadCancelled;
use App\Exceptions\DownloadException;
use App\Models\DownloadJob;
use App\Services\Download\AudioDownloader;
use App\Services\Download\ProgressWriter;
use App\Services\Library\FileService;
use App\Support\DownloadRequest;
use App\Support\PublicUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class DownloadTrackJob implements ShouldQueue
{
    use Queueable;

    /** Runs one queued download to completion. */
    public function __construct(
        public DownloadJob $record,
    ) {
        $this->onQueue((string) config('minizo.downloads.queue', 'downloads'));
    }

    /** A method rather than a $tries property, so the value comes from config at run time instead of being frozen into the serialised payload at dispatch. */
    public function tries(): int
    {
        return (int) config('minizo.downloads.tries', 3);
    }

    /** Fetch the track, then record where it landed or why it did not. */
    public function handle(AudioDownloader $downloader, FileService $files): void
    {
        // The row may have moved on while the job sat in the queue: cancelled from
        // the UI, or already run and retried.
        $this->record->refresh();

        if ($this->record->cancelRequested() || $this->record->status === DownloadStatus::Cancelled) {
            $this->record->markCancelled();

            return;
        }

        // Re-authorized here, not just at enqueue time: minutes may have passed and the
        // permission or folder access may have been revoked since.
        if (! $this->authorized()) {
            $this->record->markFailed(__('You no longer have permission to download into this folder.'));

            return;
        }

        if (! $downloader->configured()) {
            $this->record->markFailed(DownloadException::notConfigured()->getMessage());

            return;
        }

        // Re-checked here, not only at enqueue time. The host was public when the row was
        // created; DNS can answer differently by the time the worker picks it up, and the
        // fetch is what actually matters.
        if (! PublicUrl::isSafe($this->record->url)) {
            $this->record->markFailed(DownloadException::invalidUrl()->getMessage());

            return;
        }

        $this->record->markRunning();

        try {
            $result = $downloader->download(
                DownloadRequest::fromJob($this->record),
                ProgressWriter::for($this->record),
            );
        } catch (DownloadCancelled) {
            // A user-initiated stop. Not a failure, and explicitly not retried.
            $this->record->markCancelled();

            return;
        } catch (DownloadException $e) {
            // Expected failures - an unavailable video, a missing binary - are the
            // remote side's answer and will not change on a retry.
            $this->record->markFailed($e->getMessage());

            return;
        }

        $this->record->markCompleted($result->filename, $result->title, $result->artist);

        // A new file landed in the folder from outside the library service, so the
        // cached listing is now a lie and would keep hiding it for up to cache_ttl.
        $files->forgetFolder($this->record->destination());
    }

    /** Marks the row failed when the job dies for a reason handle() never saw - an out-of-memory kill, a worker restart, a genuine bug. Without this the row would sit at "Downloading" forever and only the stall reaper would eventually notice. */
    public function failed(?Throwable $exception): void
    {
        Log::error('Download job failed', [
            'download_job_id' => $this->record->getKey(),
            'url' => $this->record->url,
            'error' => $exception?->getMessage(),
        ]);

        $this->record->refresh();

        if (! $this->record->status->isTerminal()) {
            $this->record->markFailed($exception?->getMessage() ?? __('The download failed unexpectedly.'));
        }
    }

    /** Whether the requester may still download into this folder. */
    private function authorized(): bool
    {
        $user = $this->record->user;

        $gate = Gate::forUser($user);

        return $gate->allows('create', DownloadJob::class)
            && $gate->allows('view', $this->record->destination());
    }
}
