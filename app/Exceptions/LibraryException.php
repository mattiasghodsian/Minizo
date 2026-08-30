<?php

namespace App\Exceptions;

use RuntimeException;

class LibraryException extends RuntimeException
{
    /** A folder of that name is already in the library. */
    public static function folderExists(string $name): self
    {
        return new self(__('A folder named ":name" already exists.', ['name' => $name]));
    }

    /** The name is empty, reserved, or contains a path separator. */
    public static function invalidFolderName(string $name): self
    {
        return new self(__('":name" is not a valid folder name.', ['name' => $name]));
    }

    /** The folder was removed between listing it and acting on it. */
    public static function folderMissing(string $name): self
    {
        return new self(__('The folder ":name" no longer exists.', ['name' => $name]));
    }

    /** The file was removed between listing it and acting on it. */
    public static function fileMissing(string $filename): self
    {
        return new self(__('":filename" no longer exists.', ['filename' => $filename]));
    }

    /** A file of that name is already in the destination folder. */
    public static function fileExists(string $filename, string $folder): self
    {
        return new self(__('":filename" already exists in :folder.', [
            'filename' => $filename,
            'folder' => $folder,
        ]));
    }

    /** The name is empty or contains a path separator. */
    public static function invalidFilename(string $filename): self
    {
        return new self(__('":filename" is not a valid file name.', ['filename' => $filename]));
    }

    /** The move would leave the file where it already is. */
    public static function sameFolder(): self
    {
        return new self(__('That file is already in this folder.'));
    }

    /** Raised when another write holds the lock on a file. */
    public static function busy(string $filename): self
    {
        return new self(__('":filename" is being modified. Try again in a moment.', [
            'filename' => $filename,
        ]));
    }

    /** The filesystem refused a create, move or delete. */
    public static function writeFailed(string $what): self
    {
        return new self(__('Could not write to the library (:what). Check filesystem permissions.', [
            'what' => $what,
        ]));
    }
}
