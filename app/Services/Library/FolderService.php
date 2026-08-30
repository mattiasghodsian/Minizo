<?php

namespace App\Services\Library;

use App\Exceptions\LibraryException;
use App\Models\DownloadJob;
use App\Models\User;
use App\Services\Sharing\ShareService;
use App\Support\LibraryCache;
use App\Support\LibraryFolder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FolderService
{
    /** Lists, creates, renames and deletes library folders. */
    public function __construct(
        private readonly LibraryCache $cache,
    ) {}

    /**
     * Every folder in the library, unfiltered.
     *
     * @return array<int, LibraryFolder>
     */
    public function all(): array
    {
        // Cache names, not value objects: unserializing before autoload yields
        // __PHP_Incomplete_Class.
        $names = $this->cache->remember('folders', function (): array {
            try {
                $paths = $this->disk()->directories('/');
            } catch (Throwable) {
                // An unreadable library degrades to "no folders" so the sidebar still renders.
                return [];
            }

            $names = [];

            foreach ($paths as $path) {
                // basename() because some drivers return a path rather than a leaf.
                $name = basename($path);

                if (LibraryFolder::isValidName($name)) {
                    $names[] = $name;
                }
            }

            usort($names, fn (string $a, string $b): int => strnatcasecmp($a, $b));

            return $names;
        });

        return array_map(fn (string $name): LibraryFolder => new LibraryFolder($name), $names);
    }

    /**
     * The folders a user may see. This is the only listing a component should use.
     *
     * @return array<int, LibraryFolder>
     */
    public function visibleTo(User $user): array
    {
        $access = $user->folderAccess();

        if ($access->allowsAll()) {
            return $this->all();
        }

        return array_values(array_filter(
            $this->all(),
            fn (LibraryFolder $folder): bool => $access->allows($folder->name),
        ));
    }

    /**
     * Folder names only, for selects and the access editor.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_map(fn (LibraryFolder $folder): string => $folder->name, $this->all());
    }

    /** Whether a folder exists on disk, matched case-insensitively. */
    public function exists(LibraryFolder|string $folder): bool
    {
        $name = $folder instanceof LibraryFolder ? $folder->name : $folder;

        foreach ($this->all() as $candidate) {
            if ($candidate->is($name)) {
                return true;
            }
        }

        return false;
    }

    /** Resolve a route parameter to a folder, honouring the on-disk spelling. */
    public function find(?string $name): ?LibraryFolder
    {
        $requested = LibraryFolder::tryMake($name);

        if ($requested === null) {
            return null;
        }

        foreach ($this->all() as $candidate) {
            if ($candidate->is($requested)) {
                // The on-disk casing, so path building matches the real directory.
                return $candidate;
            }
        }

        return null;
    }

    /** The first folder a user can see, for redirecting into the library. */
    public function firstVisibleTo(User $user): ?LibraryFolder
    {
        return $this->visibleTo($user)[0] ?? null;
    }

    /**
     * Create a folder.
     *
     * @throws LibraryException on an invalid or duplicate name
     */
    public function create(string $name): LibraryFolder
    {
        $folder = $this->validateName($name);

        // Case-insensitive: a Windows host treats "Spanish" and "spanish" as one directory.
        if ($this->exists($folder)) {
            throw LibraryException::folderExists($folder->name);
        }

        if (! $this->disk()->makeDirectory($folder->path())) {
            throw LibraryException::writeFailed($folder->displayPath());
        }

        $this->cache->forget('folders');

        return $folder;
    }

    /**
     * Rename a folder, and follow the rename everywhere it is referenced by name.
     *
     * @throws LibraryException
     */
    public function rename(LibraryFolder $folder, string $newName): LibraryFolder
    {
        $target = $this->validateName($newName);

        if (! $this->exists($folder)) {
            throw LibraryException::folderMissing($folder->name);
        }

        // Renaming to a different casing of the same name is not a duplicate.
        if (! $folder->is($target) && $this->exists($target)) {
            throw LibraryException::folderExists($target->name);
        }

        if ($folder->name === $target->name) {
            return $folder;
        }

        if (! $this->disk()->move($folder->path(), $target->path())) {
            throw LibraryException::writeFailed($target->displayPath());
        }

        // Folders have no rows of their own, so every reference stores the name and
        // has to follow the rename.
        $this->renameInFolderAccess($folder, $target);

        app(ShareService::class)->renameFolder($folder, $target);

        DownloadJob::query()
            ->where('folder', $folder->name)
            ->update(['folder' => $target->name]);

        // The folder list and the file listings of both names.
        $this->cache->flush();

        return $target;
    }

    /**
     * Delete a folder and everything in it.
     *
     * @throws LibraryException
     */
    public function delete(LibraryFolder $folder): void
    {
        if (! $this->exists($folder)) {
            throw LibraryException::folderMissing($folder->name);
        }

        if (! $this->disk()->deleteDirectory($folder->path())) {
            throw LibraryException::writeFailed($folder->displayPath());
        }

        $this->revokeFromFolderAccess($folder);

        // Revoked, not deleted: the rows outlive the folder so the Share links screen
        // can still account for what was published out of it.
        app(ShareService::class)->revokeForFolder($folder);

        $this->cache->flush();
    }

    /**
     * @throws LibraryException
     */
    private function validateName(string $name): LibraryFolder
    {
        $folder = LibraryFolder::tryMake($name);

        if ($folder === null) {
            throw LibraryException::invalidFolderName(trim($name));
        }

        if (in_array($folder->name, (array) config('minizo.library.reserved_folder_names', []), true)) {
            throw LibraryException::invalidFolderName($folder->name);
        }

        return $folder;
    }

    /** Rewrite the folder name in every user's explicit grant list. */
    private function renameInFolderAccess(LibraryFolder $from, LibraryFolder $to): void
    {
        foreach (User::query()->whereNotNull('folder_access')->cursor() as $user) {
            $access = $user->folderAccess();

            if ($access->allowsAll() || ! $access->allows($from->name)) {
                continue;
            }

            $user->forceFill([
                'folder_access' => $access->renameFolder($from->name, $to->name)->toArray(),
            ])->save();
        }
    }

    /** Drop a deleted folder from every explicit grant list. */
    private function revokeFromFolderAccess(LibraryFolder $folder): void
    {
        foreach (User::query()->whereNotNull('folder_access')->cursor() as $user) {
            $access = $user->folderAccess();

            if ($access->allowsAll() || ! $access->allows($folder->name)) {
                continue;
            }

            // withoutFolder only needs the full list to expand "*", and those users
            // are already skipped.
            $user->forceFill([
                'folder_access' => $access->withoutFolder($folder->name, [])->toArray(),
            ])->save();
        }
    }

    /** The configured music disk. */
    private function disk(): Filesystem
    {
        return Storage::disk(config('minizo.library.disk', 'music'));
    }
}
