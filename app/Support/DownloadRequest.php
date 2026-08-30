<?php

namespace App\Support;

use App\Enums\AudioFormat;
use App\Models\DownloadJob;

final readonly class DownloadRequest
{
    /** What to download, where to put it and in which format. */
    public function __construct(
        public string $url,
        public LibraryFolder $folder,
        public AudioFormat $format,
    ) {}

    /** The request a queued job represents. */
    public static function fromJob(DownloadJob $job): self
    {
        return new self(
            url: $job->url,
            folder: $job->destination(),
            format: $job->format,
        );
    }
}
