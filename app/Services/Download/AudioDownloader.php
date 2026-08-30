<?php

namespace App\Services\Download;

use App\Exceptions\DownloadCancelled;
use App\Exceptions\DownloadException;
use App\Support\DownloadProgress;
use App\Support\DownloadRequest;
use App\Support\DownloadResult;

interface AudioDownloader
{
    /**
     * Fetch the URL and leave one audio file in the request's folder.
     *
     * @param  (callable(DownloadProgress): void)|null  $onProgress  Called many times
     *                                                               a second. Throwing from it - which is how cancellation works - aborts
     *                                                               the underlying process.
     *
     * @throws DownloadException on any failure worth showing on the row.
     * @throws DownloadCancelled when the callback asked to stop.
     */
    public function download(DownloadRequest $request, ?callable $onProgress = null): DownloadResult;

    /** Whether the environment can actually download anything. */
    public function configured(): bool;
}
