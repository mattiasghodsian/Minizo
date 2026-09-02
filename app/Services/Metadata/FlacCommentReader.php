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

    private const BLOCK_PICTURE = 6;

    /** Refuses to read a comment block larger than this. */
    private const MAX_COMMENT_BYTES = 1_048_576;

    /** The shape of what gets cached. Bump it whenever that shape changes. */
    private const CACHE_VERSION = 3;

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
        return $this->commentsFor([$file])[$file->filename] ?? [];
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
            $this->commentsFor($files),
        );
    }

    /**
     * Whether each of many files carries an embedded picture, keyed by filename.
     *
     * Read off the metadata block chain, not the picture itself: the walk this class already
     * does for the comments passes every block header, so noticing a PICTURE block costs a
     * seek. It exists so a listing can decide whether to ask for artwork at all - a page of
     * untagged tracks used to fire one cover request per row and take a 404 for every one.
     *
     * A file that is not FLAC, or cannot be read, is absent from the result rather than false.
     *
     * @param  iterable<LibraryFile>  $files
     * @return array<string, bool> filename => has an embedded picture
     */
    public function picturesFor(iterable $files): array
    {
        return array_map(
            fn (array $row): bool => $row['picture'],
            $this->allFor($files),
        );
    }

    /** Whether one file carries an embedded picture. */
    public function hasPicture(LibraryFile $file): bool
    {
        return $this->picturesFor([$file])[$file->filename] ?? false;
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
            $this->commentsFor($files),
        );
    }

    /**
     * The comment half of the parsed result, for the callers that only want tags.
     *
     * @param  iterable<LibraryFile>  $files
     * @return array<string, array<string, array<int, string>>>
     */
    private function commentsFor(iterable $files): array
    {
        return array_map(
            fn (array $row): array => $row['comments'],
            $this->allFor($files),
        );
    }

    /**
     * Everything one pass over the file learns: its comments, and whether it has a picture.
     *
     * @param  iterable<LibraryFile>  $files
     * @return array<string, array{comments: array<string, array<int, string>>, picture: bool}>
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
            $row = $this->cached($cached[$cacheKey] ?? null);

            if ($row === null) {
                $row = $misses[$cacheKey] = $this->parse($this->path($candidates[$filename]));
            }

            $results[$filename] = $row;
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
     * A cache entry, once it has been checked to hold what this class writes.
     *
     * Anything else - a miss, or an entry from a shape this class no longer writes - comes
     * back as null and is re-read from the file. The version in the key makes the second case
     * unlikely rather than impossible: a cache shared with an older build still answers.
     *
     * @return array{comments: array<string, array<int, string>>, picture: bool}|null
     */
    private function cached(mixed $entry): ?array
    {
        if (! is_array($entry) || ! is_array($entry['comments'] ?? null) || ! is_bool($entry['picture'] ?? null)) {
            return null;
        }

        $comments = [];

        foreach ($entry['comments'] as $key => $values) {
            if (! is_string($key) || ! is_array($values)) {
                return null;
            }

            $strings = array_values(array_filter($values, is_string(...)));

            // Every value this class writes is a string, so anything else means the entry did
            // not come from here and is not worth trusting the rest of.
            if (count($strings) !== count($values)) {
                return null;
            }

            $comments[$key] = $strings;
        }

        return ['comments' => $comments, 'picture' => $entry['picture']];
    }

    /**
     * @return array{comments: array<string, array<int, string>>, picture: bool}
     */
    private function parse(string $path): array
    {
        $empty = ['comments' => [], 'picture' => false];

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return $empty;
        }

        $comments = [];
        $picture = false;

        try {
            if (fread($handle, 4) !== self::MAGIC) {
                // Not a FLAC, whatever the extension says. Same posture as AudioTagReader:
                // an unreadable file is an empty result, never an exception.
                return $empty;
            }

            while (true) {
                $header = fread($handle, 4);

                if ($header === false || strlen($header) < 4) {
                    break;
                }

                $flags = ord($header[0]);
                $isLast = ($flags & 0x80) !== 0;
                $type = $flags & 0x7F;
                $length = (ord($header[1]) << 16) | (ord($header[2]) << 8) | ord($header[3]);

                if ($type === self::BLOCK_PICTURE) {
                    $picture = true;
                }

                if ($type === self::BLOCK_VORBIS_COMMENT && $comments === []) {
                    // Zero-length is a malformed block - the header alone cannot hold even
                    // the vendor string - and an oversized one is refused rather than
                    // allocated. Both are read off the file, so neither is hypothetical.
                    if ($length < 1 || $length > self::MAX_COMMENT_BYTES) {
                        break;
                    }

                    $comments = $this->comments((string) fread($handle, $length));

                    if ($isLast) {
                        break;
                    }

                    continue;
                }

                if ($isLast) {
                    // Reached the audio frames. Any block that was going to be here has been
                    // seen: a file with neither a comment nor a picture is a valid FLAC that
                    // was simply never tagged.
                    break;
                }

                // Skip the block's payload rather than read it. This is the whole speed
                // story - a PICTURE block can be hundreds of kilobytes, and knowing it is
                // there means reading its header, never its bytes.
                if (fseek($handle, $length, SEEK_CUR) !== 0) {
                    break;
                }
            }
        } catch (Throwable) {
            return $empty;
        } finally {
            fclose($handle);
        }

        return ['comments' => $comments, 'picture' => $picture];
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
