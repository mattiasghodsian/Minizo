<?php

namespace App\Jobs;

use Throwable;
use App\Services\DownloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly string $format,
        private readonly string $url,
        private readonly string $directory
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DownloadService $downloadService): void
    {
        try {
            $downloadService->getSong($this->format, $this->url, $this->directory);
        } catch (Throwable $exception) {
            Log::error('Download failed', [
                'url' => $this->url,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
            throw $exception;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Download job failed', [
            'url' => $this->url,
            'directory' => $this->directory,
            'format' => $this->format,
            'error' => $exception->getMessage()
        ]);
    }
}