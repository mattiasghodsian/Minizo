<?php

namespace App\Enums;

enum ShareType: string
{
    case Folder = 'folder';
    case Track = 'track';

    /** Whether the link points at a folder or a single track. */
    public function label(): string
    {
        return match ($this) {
            self::Folder => 'Folder',
            self::Track => 'Track',
        };
    }

    /** The all-caps kicker above the item name on the public share page. */
    public function kicker(): string
    {
        return match ($this) {
            self::Folder => 'SHARED FOLDER',
            self::Track => 'SHARED TRACK',
        };
    }

    /** Label for the download call-to-action on the public page. */
    public function downloadLabel(): string
    {
        return match ($this) {
            self::Folder => 'Download all (.zip)',
            self::Track => 'Download track',
        };
    }

    /** Whether this share resolves to many files, and so needs zip streaming. */
    public function isCollection(): bool
    {
        return $this === self::Folder;
    }
}
