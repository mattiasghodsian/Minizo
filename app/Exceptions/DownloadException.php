<?php

namespace App\Exceptions;

use RuntimeException;

class DownloadException extends RuntimeException
{
    /** Which form field the message belongs against, when it belongs on one. */
    public ?string $field = null;

    /** yt-dlp is not installed on this server. */
    public static function notConfigured(): self
    {
        return new self(__('The downloader is unavailable: yt-dlp could not be found on this server.'));
    }

    /** The submitted URL is not an http(s) link. */
    public static function invalidUrl(): self
    {
        return self::onField('url', __('Paste a full http(s) link, e.g. https://music.youtube.com/watch?v=…'));
    }

    /** A failure reported by yt-dlp itself. */
    public static function remote(string $message): self
    {
        return new self($message);
    }

    /** yt-dlp exited without reporting an error and without producing a file. */
    public static function producedNothing(): self
    {
        return new self(__('yt-dlp finished but produced no audio file.'));
    }

    /** A failure the form should show against one field. */
    public static function onField(string $field, string $message): self
    {
        $exception = new self($message);
        $exception->field = $field;

        return $exception;
    }
}
