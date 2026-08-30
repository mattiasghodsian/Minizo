<?php

namespace App\Exceptions;

use RuntimeException;

class DownloadCancelled extends RuntimeException
{
    /** The progress callback asked the download to stop. */
    public static function make(): self
    {
        return new self('The download was cancelled.');
    }
}
