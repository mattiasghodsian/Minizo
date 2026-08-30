<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

final readonly class FolderAccess
{
    /**
     * @param  array<int, string>  $folders  Empty means no access. Never contains the sentinel.
     */
    private function __construct(
        public bool $all,
        public array $folders,
    ) {}

    public const ALL = '*';

    /** Access to every folder, now and in future. */
    public static function all(): self
    {
        return new self(true, []);
    }

    /** Access to nothing. */
    public static function none(): self
    {
        return new self(false, []);
    }

    /**
     * @param  array<int, string>|null  $folders
     */
    public static function of(?array $folders): self
    {
        if ($folders === null) {
            return self::none();
        }

        $folders = array_values(array_filter(array_map(
            fn ($folder): string => trim((string) $folder),
            $folders,
        ), fn (string $folder): bool => $folder !== ''));

        if (in_array(self::ALL, $folders, true)) {
            return self::all();
        }

        return new self(false, self::unique($folders));
    }

    /** The grant stored on a user row. */
    public static function fromUser(User $user): self
    {
        return self::of($user->folder_access);
    }

    /** Whether this is the "everything" sentinel rather than a list. */
    public function allowsAll(): bool
    {
        return $this->all;
    }

    /** Whether this grant allows nothing at all. */
    public function isEmpty(): bool
    {
        return ! $this->all && $this->folders === [];
    }

    /** Whether one folder is in the grant, matched case-insensitively. */
    public function allows(string $folder): bool
    {
        if ($this->all) {
            return true;
        }

        $needle = self::key($folder);

        foreach ($this->folders as $allowed) {
            if (self::key($allowed) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep only the folders this access allows, preserving the caller's order.
     *
     * @param  iterable<int, string>  $folders
     * @return array<int, string>
     */
    public function filter(iterable $folders): array
    {
        $kept = [];

        foreach ($folders as $folder) {
            if ($this->allows($folder)) {
                $kept[] = $folder;
            }
        }

        return $kept;
    }

    /** The same grant with one more folder in it. */
    public function withFolder(string $folder): self
    {
        if ($this->all || $this->allows($folder)) {
            return $this;
        }

        return new self(false, self::unique([...$this->folders, trim($folder)]));
    }

    /**
     * Revoke one folder.
     *
     * @param  array<int, string>  $allFolders  Every folder currently in the library.
     */
    public function withoutFolder(string $folder, array $allFolders): self
    {
        $remaining = $this->all
            ? $allFolders
            : $this->folders;

        $needle = self::key($folder);

        return new self(false, self::unique(array_values(array_filter(
            $remaining,
            fn (string $candidate): bool => self::key($candidate) !== $needle,
        ))));
    }

    /**
     * Drop folders that no longer exist, so a deleted directory does not linger in someone's grant list forever.
     *
     * @param  array<int, string>  $allFolders
     */
    public function intersect(array $allFolders): self
    {
        if ($this->all) {
            return $this;
        }

        return new self(false, self::unique($this->filter($allFolders)));
    }

    /** Follow a rename, so in-app renames keep working. */
    public function renameFolder(string $from, string $to): self
    {
        if ($this->all || ! $this->allows($from)) {
            return $this;
        }

        $needle = self::key($from);

        return new self(false, self::unique(array_map(
            fn (string $folder): string => self::key($folder) === $needle ? $to : $folder,
            $this->folders,
        )));
    }

    /** "All folders" / "1 folder" / "N folders" - the Users table summary. */
    public function summaryLabel(): string
    {
        if ($this->all) {
            return 'All folders';
        }

        $count = count($this->folders);

        return $count === 1 ? '1 folder' : "{$count} folders";
    }

    /**
     * The value stored in users.folder_access.
     *
     * @return array<int, string>
     */
    public function toArray(): array
    {
        return $this->all ? [self::ALL] : $this->folders;
    }

    /**
     * @param  array<int, string>  $folders
     * @return array<int, string>
     */
    private static function unique(array $folders): array
    {
        $seen = [];
        $kept = [];

        foreach ($folders as $folder) {
            $key = self::key($folder);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $folder;
        }

        sort($kept, SORT_NATURAL | SORT_FLAG_CASE);

        return $kept;
    }

    /** The comparison form of a folder name. */
    private static function key(string $folder): string
    {
        return Str::lower(trim($folder));
    }
}
