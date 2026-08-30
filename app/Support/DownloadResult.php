<?php

namespace App\Support;

final readonly class DownloadResult
{
    /** What a finished download left on disk. */
    public function __construct(
        public string $filename,
        public ?string $title = null,
        public ?string $artist = null,
        public ?int $bytes = null,
    ) {}
}
