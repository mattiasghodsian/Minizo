<?php

namespace App\Exceptions;

use RuntimeException;

class MetadataException extends RuntimeException
{
    /** The file is not a FLAC, and nothing else can be tagged. */
    public static function notTaggable(string $filename): self
    {
        return new self(__('Minizo can only write tags to FLAC files, and ":filename" is not one.', [
            'filename' => $filename,
        ]));
    }

    /** A write was requested with no release selected. */
    public static function nothingToWrite(): self
    {
        return new self(__('There is nothing to write — pick a release first.'));
    }

    /** metaflac rejected the write and the file is unchanged. */
    public static function writeFailed(string $filename): self
    {
        return new self(__('Writing tags to ":filename" failed. The file has been left unchanged.', [
            'filename' => $filename,
        ]));
    }

    /** metaflac missing. */
    public static function toolUnavailable(): self
    {
        return new self(__('metaflac is not available on this server, so tags cannot be written. Run `php artisan minizo:doctor` to check.'));
    }

    /** metaflac is missing, so the cover cannot be embedded. */
    public static function coverToolUnavailable(): self
    {
        return new self(__('Cover art could not be embedded: metaflac is not available on this server. Run `php artisan minizo:doctor` to check.'));
    }

    /** The cover image could not be downloaded. Tags still went in. */
    public static function coverFetchFailed(): self
    {
        return new self(__('The cover art could not be downloaded. Tags were written without it.'));
    }

    /** The tags were written but the cover could not be embedded. */
    public static function coverEmbedFailed(string $detail): self
    {
        return new self(__('Tags were written, but embedding the cover art failed: :detail', [
            'detail' => $detail,
        ]));
    }

    /** MusicBrainz did not answer. */
    public static function lookupFailed(): self
    {
        return new self(__('MusicBrainz did not respond. Try again in a moment.'));
    }

    /** The per-user search rate limit was hit. */
    public static function searchThrottled(): self
    {
        return new self(__('Slow down — too many searches. Try again in a minute.'));
    }
}
