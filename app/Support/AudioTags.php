<?php

namespace App\Support;

final readonly class AudioTags
{
    /** Everything a tag reader could tell us about one file. */
    public function __construct(
        public ?string $title = null,
        public ?string $artist = null,
        public ?string $albumArtist = null,
        public ?string $album = null,
        public ?string $year = null,
        public ?string $genre = null,
        public ?string $trackNumber = null,
        public ?string $discNumber = null,
        public ?string $language = null,
        public ?string $comment = null,

        // ---- the identifiers Minizo (or Picard) wrote, if any
        public ?string $isrc = null,
        public ?string $barcode = null,
        public ?string $label = null,
        public ?string $musicbrainzTrackId = null,
        public ?string $musicbrainzAlbumId = null,

        // ---- stream facts, which no tagger controls
        public ?float $durationSeconds = null,
        public ?int $bitrate = null,
        public ?int $sampleRate = null,
        public ?int $channels = null,
        public ?int $bitsPerSample = null,
        public ?bool $lossless = null,

        // ---- artwork
        public bool $hasCover = false,
        public ?string $coverMimeType = null,
        public ?int $coverWidth = null,
        public ?int $coverHeight = null,
        public ?int $coverBytes = null,
    ) {}

    /** "2:44". Null rather than "0:00" when unknown, so a broken file reads as unknown rather than as a zero-length track. */
    public function durationLabel(): ?string
    {
        return Duration::clock($this->durationSeconds);
    }

    /** "1778 kbps". */
    public function bitrateLabel(): ?string
    {
        return $this->bitrate !== null && $this->bitrate > 0
            ? number_format($this->bitrate / 1000).' kbps'
            : null;
    }

    /** "48 kHz · 24-bit · Stereo" - the line an audio person actually looks for. */
    public function streamLabel(): ?string
    {
        $parts = array_filter([
            $this->sampleRate !== null ? number_format($this->sampleRate / 1000, $this->sampleRate % 1000 === 0 ? 0 : 1).' kHz' : null,
            $this->bitsPerSample !== null ? $this->bitsPerSample.'-bit' : null,
            match ($this->channels) {
                1 => (string) __('Mono'),
                2 => (string) __('Stereo'),
                null => null,
                default => trans_choice(':count channel|:count channels', $this->channels, ['count' => $this->channels]),
            },
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** The embedded artwork as "1400x1400 JPEG", or null when there is none. */
    public function coverLabel(): ?string
    {
        if (! $this->hasCover) {
            return null;
        }

        $parts = array_filter([
            $this->coverWidth !== null && $this->coverHeight !== null
                ? $this->coverWidth.'×'.$this->coverHeight
                : null,
            $this->coverMimeType,
            $this->coverBytes !== null ? number_format($this->coverBytes / 1024).' KB' : null,
        ]);

        return implode(' · ', $parts);
    }

    /** Whether the file has been tagged from MusicBrainz at all. */
    public function hasMusicBrainzIds(): bool
    {
        return filled($this->musicbrainzTrackId) || filled($this->musicbrainzAlbumId);
    }

    /** Whether there is anything worth showing. */
    public function isEmpty(): bool
    {
        return ! filled($this->title)
            && ! filled($this->artist)
            && ! filled($this->album);
    }

    /**
     * The editorial fields, in the order the preview lists them.
     *
     * @return array<string, string|null>
     */
    public function tagFields(): array
    {
        return [
            'TITLE' => $this->title,
            'ARTIST' => $this->artist,
            'ALBUM' => $this->album,
            'ALBUMARTIST' => $this->albumArtist,
            'DATE' => $this->year,
            'GENRE' => $this->genre,
            'TRACKNUMBER' => $this->trackNumber,
            'DISCNUMBER' => $this->discNumber,
            'LANGUAGE' => $this->language,
            'LABEL' => $this->label,
            'ISRC' => $this->isrc,
            'BARCODE' => $this->barcode,
            'MUSICBRAINZ_TRACKID' => $this->musicbrainzTrackId,
            'MUSICBRAINZ_ALBUMID' => $this->musicbrainzAlbumId,
        ];
    }

    /**
     * Wire form. Same rule as everywhere else: a value object in a public Livewire property would be re-hydrated from whatever the browser sent back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'artist' => $this->artist,
            'albumArtist' => $this->albumArtist,
            'album' => $this->album,
            'year' => $this->year,
            'genre' => $this->genre,
            'trackNumber' => $this->trackNumber,
            'discNumber' => $this->discNumber,
            'language' => $this->language,
            'comment' => $this->comment,
            'isrc' => $this->isrc,
            'barcode' => $this->barcode,
            'label' => $this->label,
            'musicbrainzTrackId' => $this->musicbrainzTrackId,
            'musicbrainzAlbumId' => $this->musicbrainzAlbumId,
            'durationSeconds' => $this->durationSeconds,
            'bitrate' => $this->bitrate,
            'sampleRate' => $this->sampleRate,
            'channels' => $this->channels,
            'bitsPerSample' => $this->bitsPerSample,
            'lossless' => $this->lossless,
            'hasCover' => $this->hasCover,
            'coverMimeType' => $this->coverMimeType,
            'coverWidth' => $this->coverWidth,
            'coverHeight' => $this->coverHeight,
            'coverBytes' => $this->coverBytes,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $string = fn (string $key): ?string => filled($row[$key] ?? null) ? (string) $row[$key] : null;
        $int = fn (string $key): ?int => isset($row[$key]) && is_numeric($row[$key]) ? (int) $row[$key] : null;

        return new self(
            title: $string('title'),
            artist: $string('artist'),
            albumArtist: $string('albumArtist'),
            album: $string('album'),
            year: $string('year'),
            genre: $string('genre'),
            trackNumber: $string('trackNumber'),
            discNumber: $string('discNumber'),
            language: $string('language'),
            comment: $string('comment'),
            isrc: $string('isrc'),
            barcode: $string('barcode'),
            label: $string('label'),
            musicbrainzTrackId: $string('musicbrainzTrackId'),
            musicbrainzAlbumId: $string('musicbrainzAlbumId'),
            durationSeconds: isset($row['durationSeconds']) && is_numeric($row['durationSeconds'])
                ? (float) $row['durationSeconds']
                : null,
            bitrate: $int('bitrate'),
            sampleRate: $int('sampleRate'),
            channels: $int('channels'),
            bitsPerSample: $int('bitsPerSample'),
            lossless: isset($row['lossless']) ? (bool) $row['lossless'] : null,
            hasCover: (bool) ($row['hasCover'] ?? false),
            coverMimeType: $string('coverMimeType'),
            coverWidth: $int('coverWidth'),
            coverHeight: $int('coverHeight'),
            coverBytes: $int('coverBytes'),
        );
    }
}
