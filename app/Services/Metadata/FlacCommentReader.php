<?php

namespace App\Services\Metadata;

use App\Support\LibraryFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FlacCommentReader
{
    /** The magic that starts every FLAC file. */
    private const MAGIC = 'fLaC';

    private const BLOCK_VORBIS_COMMENT = 4;

    /** Refuses to read a comment block larger than this. */
    private const MAX_COMMENT_BYTES = 1_048_576;

    /** The shape of what gets cached. Bump it whenever that shape changes. */
    private const CACHE_VERSION = 2;

    /** Reads Vorbis comments straight out of a FLAC, without getID3. */
    public function __construct(
        private AudioTagReader $fingerprints,
    ) {}

    /** The FIRST value of a comment, or null. */
    public function value(LibraryFile $file, string $key): ?string
    {
        return $this->list($file, $key)[0] ?? null;
    }

    /**
     * Every value of one comment, in file order.
     *
     * @return array<int, string>
     */
    public function list(LibraryFile $file, string $key): array
    {
        return $this->all($file)[strtoupper($key)] ?? [];
    }

    /**
     * Every comment in one file, keyed upper-case, each as its list of values.
     *
     * @return array<string, array<int, string>>
     */
    public function all(LibraryFile $file): array
    {
        return $this->allFor([$file])[$file->filename] ?? [];
    }

    /**
     * One comment for each of many files, keyed by filename.
     *
     * @param  iterable<LibraryFile>  $files
     * @return array<string, string|null> filename => first value
     */
    public function valuesFor(iterable $files, string $key): array
    {
        $key = strtoupper($key);

        return array_map(
            fn (array $comments): ?string => $comments[$key][0] ?? null,
            $this->allFor($files),
        );
    }

    /**
     * Several comments for each of many files, in ONE pass, each as its list of values.
     *
     * @param  iterable<LibraryFile>  $files
     * @param  array<int, string>  $keys
     * @return array<string, array<string, array<int, string>>> filename => [key => values]
     */
    public function fieldsFor(iterable $files, array $keys): array
    {
        $keys = array_map('strtoupper', $keys);

        return array_map(
            function (array $comments) use ($keys): array {
                $row = [];

                foreach ($keys as $key) {
                    $row[$key] = $comments[$key] ?? [];
                }

                return $row;
            },
            $this->allFor($files),
        );
    }

    /**
     * @param  iterable<LibraryFile>  $files
     * @return array<string, array<string, array<int, string>>>
     */
    private function allFor(iterable $files): array
    {
        /** @var array<string, LibraryFile> $candidates */
        $candidates = [];

        foreach ($files as $file) {
            // Only FLAC has a Vorbis comment block. An mp3 is listed like anything else and
            // simply has nothing to read, so it never reaches the cache at all.
            if ($file->isTaggable()) {
                $candidates[$file->filename] = $file;
            }
        }

        if ($candidates === []) {
            return [];
        }

        /** @var array<string, string> $keys filename => cache key */
        $keys = [];

        foreach ($this->fingerprints->fingerprints($candidates) as $filename => $fingerprint) {
            $keys[$filename] = 'minizo:flac:comments:v'.self::CACHE_VERSION.':'.$fingerprint;
        }

        if ($keys === []) {
            return [];
        }

        $cached = Cache::many(array_values($keys));

        $results = [];
        $misses = [];

        foreach ($keys as $filename => $cacheKey) {
            if (is_array($cached[$cacheKey] ?? null)) {
                $results[$filename] = $cached[$cacheKey];

                continue;
            }

            $results[$filename] = $misses[$cacheKey] = $this->parse($this->path($candidates[$filename]));
        }

        if ($misses !== []) {
            // The same TTL AudioTagReader uses, and long for the same reason: the key already
            // carries the file's identity, so an entry can only go stale if the file changes -
            // and then the key changes with it.
            Cache::putMany($misses, (int) config('minizo.library.tag_cache_ttl', 604800));
        }

        return $results;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function parse(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            if (fread($handle, 4) !== self::MAGIC) {
                // Not a FLAC, whatever the extension says. Same posture as AudioTagReader:
                // an unreadable file is an empty result, never an exception.
                return [];
            }

            while (true) {
                $header = fread($handle, 4);

                if ($header === false || strlen($header) < 4) {
                    return [];
                }

                $flags = ord($header[0]);
                $isLast = ($flags & 0x80) !== 0;
                $type = $flags & 0x7F;
                $length = (ord($header[1]) << 16) | (ord($header[2]) << 8) | ord($header[3]);

                if ($type === self::BLOCK_VORBIS_COMMENT) {
                    // Zero-length is a malformed block - the header alone cannot hold even
                    // the vendor string - and an oversized one is refused rather than
                    // allocated. Both are read off the file, so neither is hypothetical.
                    if ($length < 1 || $length > self::MAX_COMMENT_BYTES) {
                        return [];
                    }

                    return $this->comments((string) fread($handle, $length));
                }

                if ($isLast) {
                    // Reached the audio frames without finding a comment block: a valid FLAC
                    // that was simply never tagged.
                    return [];
                }

                // Skip the block's payload rather than read it. This is the whole speed
                // story - a PICTURE block can be hundreds of kilobytes and is stepped over.
                if (fseek($handle, $length, SEEK_CUR) !== 0) {
                    return [];
                }
            }
        } catch (Throwable) {
            return [];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function comments(string $block): array
    {
        $offset = 0;

        $vendorLength = $this->uint32($block, $offset);

        if ($vendorLength === null) {
            return [];
        }

        $offset += 4 + $vendorLength;

        $count = $this->uint32($block, $offset);

        if ($count === null) {
            return [];
        }

        $offset += 4;

        $comments = [];

        for ($i = 0; $i < $count; $i++) {
            $length = $this->uint32($block, $offset);

            if ($length === null) {
                break;
            }

            $offset += 4;

            $entry = substr($block, $offset, $length);

            if (strlen($entry) < $length) {
                // Truncated block. Whatever was read before this point is still good.
                break;
            }

            $offset += $length;

            $parts = explode('=', $entry, 2);

            if (count($parts) !== 2 || $parts[0] === '') {
                continue;
            }

            // Appended, not overwritten: Vorbis comments are multi-valued, and repeating
            // a field is how the spec expresses a list. File order is preserved, so a
            // caller wanting one value takes [0].
            $comments[strtoupper($parts[0])][] = $parts[1];
        }

        return $comments;
    }

    /** A little-endian 32-bit integer, or null if the block ends first. */
    private function uint32(string $block, int $offset): ?int
    {
        $bytes = substr($block, $offset, 4);

        if (strlen($bytes) < 4) {
            return null;
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('V', $bytes);

        return $unpacked[1];
    }

    /** The absolute path of a library file. */
    private function path(LibraryFile $file): string
    {
        return Storage::disk((string) config('minizo.library.disk', 'music'))->path($file->path());
    }
}
