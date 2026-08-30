<?php

namespace App\Services\Metadata;

use App\Exceptions\LibraryException;
use App\Exceptions\MetadataException;
use App\Services\Library\FileService;
use App\Support\LibraryFile;
use App\Support\TrackMetadata;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MetadataWriter
{
    /** Applies tags, cover art and the rename as one operation. */
    public function __construct(
        private FileService $files,
        private FlacTagWriter $tags,
        private CoverArtEmbedder $cover,
        private AudioTagReader $reader,
    ) {}

    /**
     * @throws MetadataException when the tags themselves could not be written
     * @throws LibraryException when the file is gone or another write holds its lock
     */
    public function write(LibraryFile $file, TrackMetadata $metadata, bool $rename = false): MetadataWriteResult
    {
        if (! $file->isTaggable()) {
            throw MetadataException::notTaggable($file->filename);
        }

        $disk = Storage::disk((string) config('minizo.library.disk', 'music'));

        if (! $disk->exists($file->path())) {
            throw LibraryException::fileMissing($file->filename);
        }

        // Tags and cover share one lock; both rewrite the whole file. The rename runs
        // after release, because FileService::rename takes the same non-reentrant lock.
        $warnings = $this->files->withLock($file, function () use ($disk, $file, $metadata): array {
            $path = $disk->path($file->path());

            $this->tags->write($path, $metadata);

            return array_filter([$this->embedCover($path, $metadata)]);
        });

        // Size and mtime both changed, so the cached listing is stale even when the
        // filename has not moved.
        $this->files->forgetFolder($file->folder);

        // The fingerprint key cannot detect this on its own: mtime has one-second
        // granularity and FLAC padding often absorbs a tag change without moving the size.
        $this->reader->forget($file);

        $written = $file;
        $renamed = false;

        if ($rename) {
            [$written, $renameWarning] = $this->rename($file, $metadata);
            $renamed = $written->filename !== $file->filename;

            if ($renameWarning !== null) {
                $warnings[] = $renameWarning;
            }
        }

        return new MetadataWriteResult($written, $warnings, $renamed);
    }

    /**
     * @return string|null a warning to surface, or null when there was nothing to do
     *                     and nothing went wrong
     */
    private function embedCover(string $path, TrackMetadata $metadata): ?string
    {
        // A standalone recording has no release, so Cover Art Archive has nothing to
        // key on. Not a failure - just no artwork.
        if (! filled($metadata->coverArtUrl)) {
            return null;
        }

        try {
            $this->cover->embed($path, (string) $metadata->coverArtUrl);

            return null;
        } catch (MetadataException $e) {
            // Logged as well as returned: the message reaches the user as a toast they
            // will dismiss, and an administrator debugging a missing metaflac needs it
            // to still exist afterwards.
            Log::warning('Cover art embedding failed', [
                'path' => $path,
                'cover' => $metadata->coverArtUrl,
                'error' => $e->getMessage(),
            ]);

            return $e->getMessage();
        }
    }

    /**
     * Rename to "Artist - Title.flac", if that is not already the name.
     *
     * @return array{0: LibraryFile, 1: string|null}
     */
    private function rename(LibraryFile $file, TrackMetadata $metadata): array
    {
        $suggested = $metadata->suggestedFilename($file->extension());

        if ($suggested === null || $suggested === $file->filename) {
            return [$file, null];
        }

        try {
            return [$this->files->rename($file, $suggested), null];
        } catch (LibraryException $e) {
            // Not re-raised: the tags are already on disk, and the usual cause is a file
            // of that name already existing.
            Log::info('Tags written but the file could not be renamed', [
                'file' => $file->path(),
                'to' => $suggested,
                'error' => $e->getMessage(),
            ]);

            return [$file, $e->getMessage()];
        }
    }
}
