<?php

namespace App\Support;

use App\Enums\MusicBrainzState;

final readonly class FileTags
{
    /**
     * @param  array<int, string>  $genres
     */
    public function __construct(
        public array $genres = [],
        public ?string $musicBrainzTrackId = null,
        public ?string $musicBrainzAlbumId = null,
    ) {}

    /**
     * Build from a file's Vorbis comments, as FlacCommentReader returns them.
     *
     * @param  array<string, array<int, string>>  $comments
     */
    public static function fromComments(array $comments): self
    {
        return new self(
            genres: $comments['GENRE'] ?? [],
            musicBrainzTrackId: $comments['MUSICBRAINZ_TRACKID'][0] ?? null,
            musicBrainzAlbumId: $comments['MUSICBRAINZ_ALBUMID'][0] ?? null,
        );
    }

    /** The genre shown in the column, or null when the file carries none. */
    public function genre(): ?string
    {
        return $this->genres[0] ?? null;
    }

    /** Every genre, comma-separated, for the column's tooltip. */
    public function genreList(): string
    {
        return implode(', ', $this->genres);
    }

    /** How many genres the column has to hide behind its "+N". */
    public function extraGenreCount(): int
    {
        return max(0, count($this->genres) - 1);
    }

    /** How far this file has been identified against MusicBrainz. */
    public function musicBrainz(): MusicBrainzState
    {
        return MusicBrainzState::fromIds($this->musicBrainzTrackId, $this->musicBrainzAlbumId);
    }
}
