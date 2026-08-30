<?php

namespace App\Services\Library;

use App\Exceptions\LibraryException;
use App\Services\Sharing\ShareService;
use App\Support\LibraryCache;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class FileService
{
    public const SORTABLE = ['name', 'format', 'size', 'modified'];

    /** Reads and writes the files inside library folders. */
    public function __construct(
        private readonly LibraryCache $cache,
    ) {}

    /**
     * Every listable file in a folder, name-ascending.
     *
     * @return array<int, LibraryFile>
     */
    public function all(LibraryFolder $folder): array
    {
        Gate::authorize('view', $folder);

        return $this->allUnguarded($folder);
    }

    /**
     * The same listing, without the authorization check.
     *
     * @return array<int, LibraryFile>
     */
    public function allUnguarded(LibraryFolder $folder): array
    {
        // Cache plain rows and rebuild the value objects: unserializing before autoload
        // yields __PHP_Incomplete_Class. Same note as FolderService.
        $rows = $this->cache->remember("files:{$folder->name}", function () use ($folder): array {
            try {
                $paths = $this->disk()->files($folder->path());
            } catch (Throwable) {
                return [];
            }

            $extensions = (array) config('minizo.library.extensions', []);
            $rows = [];

            foreach ($paths as $path) {
                $filename = basename($path);

                if (! LibraryFile::isValidFilename($filename)) {
                    continue;
                }

                if (! in_array(Str::lower(pathinfo($filename, PATHINFO_EXTENSION)), $extensions, true)) {
                    continue;
                }

                // One stat per file, done here so the cached listing carries size
                // and mtime and nothing downstream has to touch the disk again.
                $rows[] = [
                    'filename' => $filename,
                    'bytes' => $this->sizeOf($path),
                    'modified' => $this->modifiedAt($path)?->getTimestamp(),
                ];
            }

            usort($rows, fn (array $a, array $b): int => strnatcasecmp($a['filename'], $b['filename']));

            return $rows;
        });

        return array_map(fn (array $row): LibraryFile => new LibraryFile(
            folder: $folder,
            filename: $row['filename'],
            bytes: (int) $row['bytes'],
            modifiedAt: $row['modified'] !== null
                ? CarbonImmutable::createFromTimestamp($row['modified'])
                : null,
        ), $rows);
    }

    /**
     * Filtered, sorted, paginated - what the Files screen renders.
     *
     * @param  string  $sort  One of self::SORTABLE
     * @return LengthAwarePaginator<int, LibraryFile>
     */
    public function paginate(
        LibraryFolder $folder,
        ?string $filter = null,
        string $sort = 'name',
        bool $descending = false,
        int $perPage = 50,
        ?int $page = null,
    ): LengthAwarePaginator {
        $files = $this->sort($this->filter($this->all($folder), $filter), $sort, $descending);

        $page ??= Paginator::resolveCurrentPage();
        $perPage = max(1, $perPage);

        // Clamp the page so a stale ?page=9 after filtering shows the last page of
        // results instead of an empty table with no way back.
        $lastPage = max(1, (int) ceil(count($files) / $perPage));
        $page = min(max(1, $page), $lastPage);

        return new LengthAwarePaginator(
            items: array_slice($files, ($page - 1) * $perPage, $perPage),
            total: count($files),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );
    }

    /**
     * Case-insensitive substring match on the filename.
     *
     * @param  array<int, LibraryFile>  $files
     * @return array<int, LibraryFile>
     */
    public function filter(array $files, ?string $filter): array
    {
        $filter = trim((string) $filter);

        if ($filter === '') {
            return $files;
        }

        return array_values(array_filter(
            $files,
            fn (LibraryFile $file): bool => Str::contains($file->filename, $filter, ignoreCase: true),
        ));
    }

    /**
     * @param  array<int, LibraryFile>  $files
     * @return array<int, LibraryFile>
     */
    public function sort(array $files, string $sort, bool $descending = false): array
    {
        if (! in_array($sort, self::SORTABLE, true)) {
            $sort = 'name';
        }

        $comparators = [
            // strnatcasecmp so "track 2" sorts before "track 10".
            'name' => fn (LibraryFile $a, LibraryFile $b): int => strnatcasecmp($a->filename, $b->filename),
            'size' => fn (LibraryFile $a, LibraryFile $b): int => $a->bytes <=> $b->bytes,
            'modified' => fn (LibraryFile $a, LibraryFile $b): int => ($a->modifiedAt?->getTimestamp() ?? 0) <=> ($b->modifiedAt?->getTimestamp() ?? 0),
            // Ties within a format fall back to name, otherwise a format sort
            // scrambles the order of everything sharing an extension.
            'format' => fn (LibraryFile $a, LibraryFile $b): int => [$a->extension(), 0] === [$b->extension(), 0]
                ? strnatcasecmp($a->filename, $b->filename)
                : strcmp($a->extension(), $b->extension()),
        ];

        usort($files, $comparators[$sort]);

        return $descending ? array_reverse($files) : $files;
    }

    /** Resolve one file, or null when it is gone. */
    public function find(LibraryFolder $folder, string $filename): ?LibraryFile
    {
        return $this->pick($this->all($folder), $filename);
    }

    /** find() without the authorization check. */
    public function findUnguarded(LibraryFolder $folder, string $filename): ?LibraryFile
    {
        return $this->pick($this->allUnguarded($folder), $filename);
    }

    /**
     * Case-insensitive, because a Windows host reports a name whose case may not match what was stored.
     *
     * @param  array<int, LibraryFile>  $files
     */
    private function pick(array $files, string $filename): ?LibraryFile
    {
        foreach ($files as $file) {
            if (Str::lower($file->filename) === Str::lower($filename)) {
                return $file;
            }
        }

        return null;
    }

    /** Total bytes in a folder, for the share page's meta line. */
    public function totalBytes(LibraryFolder $folder): int
    {
        return array_sum(array_map(
            fn (LibraryFile $file): int => $file->bytes,
            $this->all($folder),
        ));
    }

    /** How many tracks a folder holds. */
    public function count(LibraryFolder $folder): int
    {
        return count($this->all($folder));
    }

    /**
     * Move a file to another folder.
     *
     * @throws LibraryException
     */
    public function move(LibraryFile $file, LibraryFolder $destination): LibraryFile
    {
        if ($file->folder->is($destination)) {
            throw LibraryException::sameFolder();
        }

        return $this->withLock($file, function () use ($file, $destination): LibraryFile {
            $target = new LibraryFile($destination, $file->filename, $file->bytes, $file->modifiedAt);

            $this->assertExists($file);

            // Never overwrite. Two different tracks can easily share a filename
            // across folders, and silently clobbering one is unrecoverable.
            if ($this->disk()->exists($target->path())) {
                throw LibraryException::fileExists($file->filename, $destination->name);
            }

            if (! $this->disk()->move($file->path(), $target->path())) {
                throw LibraryException::writeFailed($target->path());
            }

            // Both folders' listings are now wrong.
            $this->forgetFolder($file->folder);
            $this->forgetFolder($destination);

            // A track share follows the file, so a link stays live across a move.
            app(ShareService::class)->moveFile($file, $target);

            return $target;
        });
    }

    /**
     * Rename a file within its folder.
     *
     * @throws LibraryException
     */
    public function rename(LibraryFile $file, string $newName): LibraryFile
    {
        $newName = trim($newName);

        if (! LibraryFile::isValidFilename($newName)) {
            throw LibraryException::invalidFilename($newName);
        }

        return $this->withLock($file, function () use ($file, $newName): LibraryFile {
            $target = new LibraryFile($file->folder, $newName, $file->bytes, $file->modifiedAt);

            $this->assertExists($file);

            if ($file->filename === $newName) {
                return $file;
            }

            // A case-only rename is legitimate and must not be read as a collision
            // with itself - but on a case-insensitive host it is also a no-op that
            // some drivers reject, so it is allowed through to the driver.
            $collides = Str::lower($file->filename) !== Str::lower($newName)
                && $this->disk()->exists($target->path());

            if ($collides) {
                throw LibraryException::fileExists($newName, $file->folder->name);
            }

            if (! $this->disk()->move($file->path(), $target->path())) {
                throw LibraryException::writeFailed($target->path());
            }

            $this->forgetFolder($file->folder);

            app(ShareService::class)->renameFile($file, $target);

            return $target;
        });
    }

    /**
     * Delete a file. There is no undo - the design's copy says "Permanently".
     *
     * @throws LibraryException
     */
    public function delete(LibraryFile $file): void
    {
        $this->withLock($file, function () use ($file): void {
            $this->assertExists($file);

            if (! $this->disk()->delete($file->path())) {
                throw LibraryException::writeFailed($file->path());
            }

            $this->forgetFolder($file->folder);

            // A link to a deleted file must stop resolving rather than 404 mid-download.
            // Revoked, not deleted, so the Share links screen still accounts for it.
            app(ShareService::class)->revokeForFile($file);
        });
    }

    /** Drop a folder's cached listing. */
    public function forgetFolder(LibraryFolder $folder): void
    {
        $this->cache->forget("files:{$folder->name}");
    }

    /**
     * Serialise writes to one path.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws LibraryException when another write holds the lock
     */
    public function withLock(LibraryFile $file, Closure $callback): mixed
    {
        $lock = Cache::lock('minizo:file:'.Str::lower($file->path()), 15);

        if (! $lock->get()) {
            throw LibraryException::busy($file->filename);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws LibraryException
     */
    private function assertExists(LibraryFile $file): void
    {
        if (! $this->disk()->exists($file->path())) {
            throw LibraryException::fileMissing($file->filename);
        }
    }

    /** Size in bytes, or zero when the file cannot be read. */
    private function sizeOf(string $path): int
    {
        try {
            return (int) $this->disk()->size($path);
        } catch (Throwable) {
            return 0;
        }
    }

    /** Last modified time, or null when the file cannot be read. */
    private function modifiedAt(string $path): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromTimestamp($this->disk()->lastModified($path));
        } catch (Throwable) {
            return null;
        }
    }

    /** The configured music disk. */
    private function disk(): Filesystem
    {
        return Storage::disk(config('minizo.library.disk', 'music'));
    }
}
