<?php

namespace App\Support;

final readonly class TrackMetadata
{
    /**
     * @param  array<int, string>  $genres
     */
    public function __construct(
        public ?string $title = null,
        public ?string $artist = null,
        public ?string $albumArtist = null,
        public ?string $album = null,
        public ?string $year = null,
        public ?string $trackNumber = null,
        public ?int $totalTracks = null,
        public ?int $lengthMs = null,
        public ?string $isrc = null,
        public ?string $barcode = null,
        public ?string $label = null,
        public ?string $status = null,
        public ?string $mediaFormat = null,
        public ?string $country = null,
        public ?string $language = null,
        public array $genres = [],
        public ?string $releaseId = null,
        public ?string $recordingId = null,
        public ?string $link = null,
        public ?string $coverArtUrl = null,
        /** True when this came from a recording with no release. */
        public bool $standalone = false,
    ) {}

    /** The genres as one comma-separated string, or null when there are none. */
    public function genre(): ?string
    {
        return $this->genres[0] ?? null;
    }

    /**
     * Split a typed genre field into the list that will be written.
     *
     * @return array<int, string>
     */
    public static function splitGenres(string $value): array
    {
        $parts = preg_split('/\s*[,;]\s*/u', trim($value)) ?: [];

        $genres = [];
        $seen = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $key = mb_strtolower($part);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $genres[] = $part;
        }

        return $genres;
    }

    /** The genres as the editor's input shows them. */
    public function genreList(): string
    {
        return implode(', ', $this->genres);
    }

    /** Track length as "m:ss". */
    public function lengthLabel(): ?string
    {
        return Duration::clockFromMs($this->lengthMs);
    }

    /** Whether there is enough here to be worth writing. */
    public function isWritable(): bool
    {
        return filled($this->title) || filled($this->artist);
    }

    /** The filename the "Rename file" checkbox produces: "%Artist% - %Track%.%ext%". */
    public function suggestedFilename(string $extension): ?string
    {
        $artist = self::filenameSafe($this->artist ?? '');
        $title = self::filenameSafe($this->title ?? '');

        if ($title === '') {
            return null;
        }

        $stem = $artist !== '' ? $artist.' - '.$title : $title;

        return $stem.'.'.$extension;
    }

    /** A value with the characters a filename cannot carry removed. */
    private static function filenameSafe(string $value): string
    {
        // Control characters plus the nine Windows forbids, and a trailing dot or
        // space (which Windows silently drops, making the name unmatchable).
        $value = preg_replace('/[\x00-\x1F<>:"|?*\/\\\\]+/u', '', $value) ?? '';

        return trim($value, " .\t\n\r\0\x0B");
    }

    /**
     * Merge user edits over the resolved values.
     *
     * @param  array<string, string|null>  $overrides
     */
    public function withOverrides(array $overrides): self
    {
        $pick = fn (string $key, ?string $current): ?string => array_key_exists($key, $overrides)
            && filled($overrides[$key]) ? trim((string) $overrides[$key]) : $current;

        return new self(
            title: $pick('title', $this->title),
            artist: $pick('artist', $this->artist),
            albumArtist: $this->albumArtist,
            album: $pick('album', $this->album),
            year: $pick('year', $this->year),
            trackNumber: $this->trackNumber,
            totalTracks: $this->totalTracks,
            lengthMs: $this->lengthMs,
            isrc: $this->isrc,
            barcode: $this->barcode,
            label: $this->label,
            status: $this->status,
            mediaFormat: $this->mediaFormat,
            country: $this->country,
            language: $this->language,
            // Genre is the one field where an empty override is honoured rather than
            // falling back. Its input is pre-filled with the real value, so blank means
            // the user cleared it; falling back would restore every suggestion at once.
            genres: array_key_exists('genre', $overrides)
                ? self::splitGenres((string) $overrides['genre'])
                : $this->genres,
            releaseId: $this->releaseId,
            recordingId: $this->recordingId,
            link: $this->link,
            coverArtUrl: $this->coverArtUrl,
            standalone: $this->standalone,
        );
    }

    /**
     * Wire form.
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
            'trackNumber' => $this->trackNumber,
            'totalTracks' => $this->totalTracks,
            'lengthMs' => $this->lengthMs,
            'isrc' => $this->isrc,
            'barcode' => $this->barcode,
            'label' => $this->label,
            'status' => $this->status,
            'mediaFormat' => $this->mediaFormat,
            'country' => $this->country,
            'language' => $this->language,
            'genres' => $this->genres,
            'releaseId' => $this->releaseId,
            'recordingId' => $this->recordingId,
            'link' => $this->link,
            'coverArtUrl' => $this->coverArtUrl,
            'standalone' => $this->standalone,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $string = fn (string $key): ?string => filled($row[$key] ?? null) ? (string) $row[$key] : null;

        return new self(
            title: $string('title'),
            artist: $string('artist'),
            albumArtist: $string('albumArtist'),
            album: $string('album'),
            year: $string('year'),
            trackNumber: $string('trackNumber'),
            totalTracks: isset($row['totalTracks']) ? (int) $row['totalTracks'] : null,
            lengthMs: isset($row['lengthMs']) ? (int) $row['lengthMs'] : null,
            isrc: $string('isrc'),
            barcode: $string('barcode'),
            label: $string('label'),
            status: $string('status'),
            mediaFormat: $string('mediaFormat'),
            country: $string('country'),
            language: $string('language'),
            genres: array_values(array_filter(
                is_array($row['genres'] ?? null) ? $row['genres'] : [],
                fn ($genre): bool => is_string($genre) && $genre !== '',
            )),
            releaseId: $string('releaseId'),
            recordingId: $string('recordingId'),
            link: $string('link'),

            // The server fetches this URL, so an unvalidated value is a server-side
            // request forgery against hosts only reachable from inside the network.
            coverArtUrl: self::safeCoverUrl($row['coverArtUrl'] ?? null),

            standalone: (bool) ($row['standalone'] ?? false),
        );
    }

    /**
     * Whether a URL is one cover art may be fetched from.
     *
     * Public because the check has to happen twice: once on the way in, and again on the
     * URL that actually served the bytes, since the archive answers with a redirect.
     */
    public static function isAllowedCoverHost(string $url): bool
    {
        return self::safeCoverUrl($url) !== null;
    }

    /** Cover art may only come from where cover art comes from. */
    private static function safeCoverUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (['coverartarchive.org', 'archive.org'] as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * The design's step-3 grid, in its order: label => value.
     *
     * @return array<string, string|null>
     */
    public function displayFields(): array
    {
        return [
            'RELEASE_ID' => $this->releaseId,
            'TITLE' => $this->title,
            'ARTIST' => $this->artist,
            'ALBUM' => $this->album,
            'YEAR' => $this->year,
            'LABEL' => $this->label,
            'TRACK_NUMBER' => $this->trackNumber,
            'TOTAL_TRACKS' => $this->totalTracks !== null ? (string) $this->totalTracks : null,
            'LENGTH' => $this->lengthLabel(),
            'ISRC' => $this->isrc,
            'BARCODE' => $this->barcode,
            'STATUS' => $this->status,
            'FORMAT' => $this->mediaFormat,
            'COUNTRY' => $this->country,
            'LANGUAGE' => $this->language,
        ];
    }
}
