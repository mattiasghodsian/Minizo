<?php

namespace App\Services\Metadata;

use App\Support\AudioTags;
use App\Support\LibraryFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Kiwilan\Audio\Audio;
use Throwable;

class AudioTagReader
{
    /** Never throws. A file can be truncated, half-written by a download in flight, or simply not the format its extension claims - and a preview that 500s is worse than one that says "could not read this file". */
    public function read(LibraryFile $file): ?AudioTags
    {
        $key = $this->cacheKey($file, 'tags');

        if ($key === null) {
            return null;
        }

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return AudioTags::fromArray($cached);
        }

        $tags = $this->parse($file);

        if ($tags === null) {
            return null;
        }

        Cache::put($key, $tags->toArray(), $this->ttl());

        return $tags;
    }

    /**
     * The embedded cover's bytes, or null when there is none.
     *
     * @return array{contents: string, mime: string}|null
     */
    public function cover(LibraryFile $file): ?array
    {
        try {
            $audio = Audio::read($this->path($file));
            $cover = $audio->getCover();

            if ($cover === null) {
                return null;
            }

            $contents = $cover->getContents();

            if (! is_string($contents) || $contents === '') {
                return null;
            }

            return [
                'contents' => $contents,
                'mime' => $cover->getMimeType() ?: 'image/jpeg',
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether a cover exists, without reading its bytes. */
    public function hasCover(LibraryFile $file): bool
    {
        $tags = $this->read($file);

        return $tags !== null && $tags->hasCover;
    }

    /** A short identity for the cache key and the HTTP ETag. */
    public function fingerprint(LibraryFile $file): ?string
    {
        $disk = $this->disk();

        if (! $disk->exists($file->path())) {
            return null;
        }

        // PHP caches stat() per request, so a write earlier in the same request would
        // otherwise read back its old mtime and size.
        clearstatcache(true, $disk->path($file->path()));

        $revision = (int) Cache::get($this->revisionKey($file), 0);

        return substr(sha1(implode('|', [
            $file->path(),
            $disk->lastModified($file->path()),
            $disk->size($file->path()),
            // Bumped by forget(), so an in-app write changes the identity even when mtime
            // and size cannot - which is what makes the ETag correct after a re-tag.
            $revision,
        ])), 0, 16);
    }

    /**
     * Fingerprints for many files at once, keyed by filename.
     *
     * @param  iterable<LibraryFile>  $files
     * @return array<string, string> filename => fingerprint
     */
    public function fingerprints(iterable $files): array
    {
        $disk = $this->disk();

        /** @var array<string, LibraryFile> $present */
        $present = [];

        foreach ($files as $file) {
            if ($disk->exists($file->path())) {
                $present[$file->filename] = $file;
            }
        }

        if ($present === []) {
            return [];
        }

        // array_values, because Cache::many() reads a string-keyed array as
        // [key => default]. Passing the keyed array serves stale values forever.
        $revisions = Cache::many(array_values(array_map(
            fn (LibraryFile $file): string => $this->revisionKey($file),
            $present,
        )));

        $fingerprints = [];

        foreach ($present as $filename => $file) {
            // PHP caches stat() per request, so a write earlier in this request would
            // otherwise be invisible here. Same reason as in fingerprint().
            clearstatcache(true, $disk->path($file->path()));

            $fingerprints[$filename] = substr(sha1(implode('|', [
                $file->path(),
                $disk->lastModified($file->path()),
                $disk->size($file->path()),
                (int) ($revisions[$this->revisionKey($file)] ?? 0),
            ])), 0, 16);
        }

        return $fingerprints;
    }

    /** Drop everything cached about a file, and change its identity. */
    public function forget(LibraryFile $file): void
    {
        $key = $this->cacheKey($file, 'tags');

        if ($key !== null) {
            Cache::forget($key);
        }

        Cache::forever(
            $this->revisionKey($file),
            (int) Cache::get($this->revisionKey($file), 0) + 1,
        );
    }

    /** The cache key holding a file fingerprint. */
    private function revisionKey(LibraryFile $file): string
    {
        return 'minizo:audio:rev:'.sha1($file->path());
    }

    /** Read one file with getID3, or null when it is not audio. */
    private function parse(LibraryFile $file): ?AudioTags
    {
        try {
            $audio = Audio::read($this->path($file));
        } catch (Throwable) {
            return null;
        }

        $metadata = $audio->getMetadata();

        // getID3 returns an empty result rather than throwing on a non-audio file, so
        // unreadable has to be detected. dataFormat is null for anything it could not parse.
        if ($metadata === null || $metadata->getDataFormat() === null) {
            return null;
        }

        $cover = $audio->getCover();

        // From getID3's raw audio block; AudioMetadata does not expose bit depth.
        $rawAudio = (array) Arr::get((array) $audio->getId3Reader()?->getRaw(), 'audio', []);

        return new AudioTags(
            title: $this->clean($audio->getTitle()),
            artist: $this->clean($audio->getArtist()),
            albumArtist: $this->clean($audio->getAlbumArtist()),
            album: $this->clean($audio->getAlbum()),
            year: $audio->getYear() !== null ? (string) $audio->getYear() : null,
            genre: $this->clean($audio->getGenre()),
            trackNumber: $this->clean($audio->getTrackNumber()),
            discNumber: $this->clean($audio->getDiscNumber()),
            // php-audio does not map the Vorbis LANGUAGE field to getLanguage(), so it
            // falls back to the raw comment.
            language: $this->clean($audio->getLanguage()) ?? $this->rawTag($audio, 'language'),
            comment: $this->clean($audio->getComment()),

            // The Vorbis fields with no dedicated getter - the same names FlacTagWriter
            // writes, so a file Minizo tagged reads back symmetrically.
            isrc: $this->rawTag($audio, 'isrc'),
            barcode: $this->rawTag($audio, 'barcode'),
            label: $this->rawTag($audio, 'label'),
            musicbrainzTrackId: $this->rawTag($audio, 'musicbrainz_trackid'),
            musicbrainzAlbumId: $this->rawTag($audio, 'musicbrainz_albumid'),

            durationSeconds: $audio->getDuration(),
            // Not nullsafe: the guard above already returned for a null $metadata.
            bitrate: $metadata->getBitrate(),
            sampleRate: $metadata->getSampleRate(),
            channels: $metadata->getChannels(),
            bitsPerSample: isset($rawAudio['bits_per_sample']) ? (int) $rawAudio['bits_per_sample'] : null,
            lossless: isset($rawAudio['lossless']) ? (bool) $rawAudio['lossless'] : null,

            hasCover: $cover !== null,
            coverMimeType: $cover?->getMimeType(),
            coverWidth: $cover?->getWidth(),
            coverHeight: $cover?->getHeight(),
            // strlen of the binary, which is why hasCover is answered from the cached
            // tags rather than by re-reading the picture block.
            coverBytes: $cover !== null ? strlen((string) $cover->getContents()) : null,
        );
    }

    /** Read a raw Vorbis comment, case-insensitively. */
    private function rawTag(Audio $audio, string $key): ?string
    {
        foreach ($audio->getRawAll() as $tags) {
            foreach ((array) $tags as $name => $value) {
                if (strcasecmp((string) $name, $key) !== 0) {
                    continue;
                }

                $value = is_array($value) ? ($value[0] ?? null) : $value;

                if (filled($value)) {
                    return $this->clean((string) $value);
                }
            }
        }

        return null;
    }

    /** Trim a tag value, treating an empty one as absent. */
    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** The cache key for one file, or null when it cannot be fingerprinted. */
    private function cacheKey(LibraryFile $file, string $suffix): ?string
    {
        $fingerprint = $this->fingerprint($file);

        return $fingerprint === null ? null : "minizo:audio:{$suffix}:{$fingerprint}";
    }

    /** How long a parsed result stays cached. */
    private function ttl(): int
    {
        // Long, because the key already contains the file's identity - an entry can only
        // go stale if the file changes, and then the key changes with it.
        return (int) config('minizo.library.tag_cache_ttl', 604800);
    }

    /** The absolute path of a library file. */
    private function path(LibraryFile $file): string
    {
        return $this->disk()->path($file->path());
    }

    /** The configured music disk. */
    private function disk(): Filesystem
    {
        return Storage::disk((string) config('minizo.library.disk', 'music'));
    }
}
