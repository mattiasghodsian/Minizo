<?php

namespace App\Support;

use App\Enums\ReleaseType;

final readonly class ReleaseCandidate
{
    /** One MusicBrainz release offered in step one of the editor. */
    public function __construct(
        /** Release MBID, or a recording MBID when this is a standalone. */
        public string $id,
        public string $title,
        public string $artist,
        public ReleaseType $type,
        public ?string $date = null,
        public ?string $country = null,
        public ?string $status = null,
        public int $trackCount = 1,
        public int $score = 0,
        /** Set only for a standalone, where the recording IS the track. */
        public ?int $lengthMs = null,
    ) {}

    /** Whether this is a recording with no release behind it. */
    public function isStandalone(): bool
    {
        return $this->type === ReleaseType::Standalone;
    }

    /** The release year, or null when the date is unknown. */
    public function year(): ?string
    {
        return $this->date !== null && $this->date !== '' ? substr($this->date, 0, 4) : null;
    }

    /**
     * Wire form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'artist' => $this->artist,
            'type' => $this->type->value,
            'date' => $this->date,
            'country' => $this->country,
            'status' => $this->status,
            'trackCount' => $this->trackCount,
            'score' => $this->score,
            'lengthMs' => $this->lengthMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (string) ($row['id'] ?? ''),
            title: (string) ($row['title'] ?? ''),
            artist: (string) ($row['artist'] ?? ''),
            // tryFrom, not from: the value arrives from the client, so an unknown
            // string must degrade rather than throw.
            type: ReleaseType::tryFrom((string) ($row['type'] ?? '')) ?? ReleaseType::Other,
            date: $row['date'] ?? null,
            country: $row['country'] ?? null,
            status: $row['status'] ?? null,
            trackCount: (int) ($row['trackCount'] ?? 1),
            score: (int) ($row['score'] ?? 0),
            lengthMs: isset($row['lengthMs']) ? (int) $row['lengthMs'] : null,
        );
    }

    /** Dedup key. */
    public function key(): string
    {
        return $this->type === ReleaseType::Standalone
            ? 'recording:'.$this->id
            : 'release:'.$this->id;
    }
}
