<?php

namespace Tests\Support;

use App\Services\Download\AudioDownloader;
use App\Support\DownloadProgress;
use App\Support\DownloadRequest;
use App\Support\DownloadResult;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** A downloader that touches no network and no binary. */
class FakeAudioDownloader implements AudioDownloader
{
    /** Progress percentages to emit before finishing. */
    public array $emit = [];

    /** Thrown instead of succeeding, when set. */
    public ?Throwable $throw = null;

    public bool $configured = true;

    /** Requests this instance was asked to perform. */
    public array $calls = [];

    public string $filename = 'Bad Bunny - Monaco.flac';

    public ?string $title = 'Monaco';

    public ?string $artist = 'Bad Bunny';

    /** Whether to actually create the file on the fake disk. */
    public bool $writeFile = false;

    public function download(DownloadRequest $request, ?callable $onProgress = null): DownloadResult
    {
        $this->calls[] = $request;

        foreach ($this->emit as $percent) {
            if ($onProgress === null) {
                break;
            }

            // Unguarded: a throw from here is how cancellation works, and it must
            // propagate exactly as it would from the real downloader.
            $onProgress(DownloadProgress::fromCallback(
                $this->filename, $percent.'%', '38.40MiB', '2.10MiB/s', '00:12',
            ));
        }

        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->writeFile) {
            Storage::disk('music')->put(
                $request->folder->path().'/'.$this->filename,
                'fake audio',
            );
        }

        return new DownloadResult(
            filename: $this->filename,
            title: $this->title,
            artist: $this->artist,
            bytes: 10,
        );
    }

    public function configured(): bool
    {
        return $this->configured;
    }
}
