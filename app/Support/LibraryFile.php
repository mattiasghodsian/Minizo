<?php

namespace App\Support;

use App\Enums\AudioFormat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class LibraryFile
{
    /** One file in the library, identified by its folder and name. */
    public function __construct(
        public LibraryFolder $folder,
        public string $filename,
        public int $bytes = 0,
        public ?CarbonImmutable $modifiedAt = null,
    ) {
        if (! self::isValidFilename($filename)) {
            throw new \InvalidArgumentException("Invalid library filename: [{$filename}]");
        }
    }

    /** The path relative to the music disk root. */
    public function path(): string
    {
        return $this->folder->name.'/'.$this->filename;
    }

    /** The filename without its extension - what the metadata editor pre-fills from, and what the "Search on YouTube Music" action uses. */
    public function basename(): string
    {
        return pathinfo($this->filename, PATHINFO_FILENAME);
    }

    /** The lowercased extension, without the dot. */
    public function extension(): string
    {
        return Str::lower(pathinfo($this->filename, PATHINFO_EXTENSION));
    }

    /** The format, if it is one Minizo can write tags to. Null for everything else. */
    public function format(): ?AudioFormat
    {
        return AudioFormat::fromFilename($this->filename);
    }

    /** Whether Minizo can write metadata to this file. */
    public function isTaggable(): bool
    {
        return $this->format() !== null;
    }

    /** The label shown in the FORMAT column: the real extension, uppercased. */
    public function formatLabel(): string
    {
        return mb_strtoupper($this->extension());
    }

    /** Size as the design shows it: "42.10 MB". */
    public function sizeLabel(): string
    {
        return FileSize::label($this->bytes);
    }

    /** Whether two references point at the same file on disk. */
    public function is(self $other): bool
    {
        return $this->folder->is($other->folder)
            && Str::lower($this->filename) === Str::lower($other->filename);
    }

    /** Whether a name is safe to use as a library filename. */
    public static function isValidFilename(string $filename): bool
    {
        $filename = trim($filename);

        if ($filename === '' || mb_strlen($filename) > 255) {
            return false;
        }

        if (str_contains($filename, '/') || str_contains($filename, '\\')) {
            return false;
        }

        if (str_starts_with($filename, '.')) {
            return false;
        }

        return preg_match('/[\x00-\x1F<>:"|?*]/', $filename) !== 1;
    }

    /** The folder-qualified path, for logs and error messages. */
    public function __toString(): string
    {
        return $this->path();
    }
}
