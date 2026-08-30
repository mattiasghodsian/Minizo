<?php

namespace App\Exceptions;

use RuntimeException;

class ShareException extends RuntimeException
{
    /** Public sharing is switched off for the instance. */
    public static function disabled(): self
    {
        return new self(__('Public sharing is currently disabled on this instance.'));
    }

    /** The folder being shared is no longer on disk. */
    public static function folderMissing(string $folder): self
    {
        return new self(__('The folder ":folder" no longer exists, so it cannot be shared.', [
            'folder' => $folder,
        ]));
    }

    /** The file being shared is no longer on disk. */
    public static function fileMissing(string $filename): self
    {
        return new self(__('":filename" no longer exists, so it cannot be shared.', [
            'filename' => $filename,
        ]));
    }

    /** The folder holds no tracks, so the link would download nothing. */
    public static function emptyFolder(string $folder): self
    {
        return new self(__('":folder" has no tracks in it — there would be nothing to download.', [
            'folder' => $folder,
        ]));
    }
}
