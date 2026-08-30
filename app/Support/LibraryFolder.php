<?php

namespace App\Support;

use Illuminate\Support\Str;

final readonly class LibraryFolder
{
    /** One folder in the library, identified by name. */
    public function __construct(
        public string $name,
    ) {
        if (! self::isValidName($name)) {
            throw new \InvalidArgumentException("Invalid library folder name: [{$name}]");
        }
    }

    /** Build a folder from a name, throwing when it is not usable. */
    public static function make(string $name): self
    {
        return new self(trim($name));
    }

    /** Null instead of an exception, for user input that has not been validated yet. */
    public static function tryMake(?string $name): ?self
    {
        $name = trim((string) $name);

        return self::isValidName($name) ? new self($name) : null;
    }

    /** The path relative to the music disk root. */
    public function path(): string
    {
        return $this->name;
    }

    /** The path as shown to users, e.g. the "/music/Spanish" preview in the new-folder modal. */
    public function displayPath(): string
    {
        return '/music/'.$this->name;
    }

    /** Whether two references name the same folder, ignoring case. */
    public function is(self|string $other): bool
    {
        $name = $other instanceof self ? $other->name : $other;

        return Str::lower($this->name) === Str::lower(trim($name));
    }

    /** Whether a name is safe to use as a library folder. */
    public static function isValidName(string $name): bool
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 255) {
            return false;
        }

        // No separators, no traversal, no leading dot (which would hide the folder
        // and collide with "." / "..").
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            return false;
        }

        if (str_starts_with($name, '.')) {
            return false;
        }

        // Control characters and the characters Windows forbids in a filename, so
        // a folder created on Linux is not unusable on a Windows host.
        return preg_match('/[\x00-\x1F<>:"|?*]/', $name) !== 1;
    }

    /** The folder name. */
    public function __toString(): string
    {
        return $this->name;
    }
}
