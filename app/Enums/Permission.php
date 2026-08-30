<?php

namespace App\Enums;

enum Permission: string
{
    case Edit = 'edit';
    case Move = 'move';
    case Download = 'download';
    case Delete = 'delete';
    case Downloader = 'downloader';
    case Share = 'share';

    /** The permission name as the Manage-user screen shows it. */
    public function label(): string
    {
        return match ($this) {
            self::Edit => 'Edit metadata',
            self::Move => 'Move files',
            self::Download => 'Download files',
            self::Delete => 'Delete files',
            self::Downloader => 'Use downloader',
            self::Share => 'Share links',
        };
    }

    /** The compact form for the Users table's summary column. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Edit => 'Edit',
            self::Move => 'Move',
            self::Download => 'Download',
            self::Delete => 'Delete',
            self::Downloader => 'Downloader',
            self::Share => 'Share',
        };
    }

    /** The sub-label shown under each toggle in the Manage-user modal. */
    public function description(): string
    {
        return match ($this) {
            self::Edit => 'Search MusicBrainz and write tags',
            self::Move => 'Move tracks between folders',
            self::Download => 'Download tracks to their device',
            self::Delete => 'Permanently delete tracks from disk',
            self::Downloader => 'Queue new downloads from the Download page',
            self::Share => 'Create expiring public links to tracks & folders',
        };
    }

    /** The `users` column backing this permission. */
    public function column(): string
    {
        return 'can_'.$this->value;
    }

    /** Whether the whole instance can switch this permission off for everyone. */
    public function hasGlobalSwitch(): bool
    {
        return $this === self::Share;
    }

    /**
     * All permission column names, for migrations and mass-assignment lists.
     *
     * @return array<int, string>
     */
    public static function columns(): array
    {
        return array_map(fn (self $permission): string => $permission->column(), self::cases());
    }
}
