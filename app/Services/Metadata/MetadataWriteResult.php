<?php

namespace App\Services\Metadata;

use App\Support\LibraryFile;

final readonly class MetadataWriteResult
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        /** The file after the operation - renamed, if a rename was asked for and worked. */
        public LibraryFile $file,
        public array $warnings = [],
        public bool $renamed = false,
    ) {}

    /** Whether the tags went in but something else did not. */
    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /** The warnings as one line, or null when there are none. */
    public function warningText(): ?string
    {
        return $this->warnings === [] ? null : implode(' ', $this->warnings);
    }
}
