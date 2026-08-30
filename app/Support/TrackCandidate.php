<?php

namespace App\Support;

final readonly class TrackCandidate
{
    /** One track offered in step two of the editor. */
    public function __construct(
        public int $mediaPosition,
        public int $trackIndex,
        /** The printed track number, which is not always a number ("A1" on vinyl). */
        public string $number,
        public string $title,
        public ?int $lengthMs,
        public string $recordingId,
        public float $matchScore,
        public bool $isBestMatch,
        public ?string $mediaFormat = null,
    ) {}

    /** Duration as the design shows it: "3:12", or a dash when unknown. */
    public function durationLabel(): string
    {
        return Duration::clockFromMs($this->lengthMs) ?? '—';
    }

    /** Identifies the row across a Livewire round trip. */
    public function key(): string
    {
        return $this->mediaPosition.':'.$this->trackIndex;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mediaPosition' => $this->mediaPosition,
            'trackIndex' => $this->trackIndex,
            'number' => $this->number,
            'title' => $this->title,
            'lengthMs' => $this->lengthMs,
            'recordingId' => $this->recordingId,
            'matchScore' => $this->matchScore,
            'isBestMatch' => $this->isBestMatch,
            'mediaFormat' => $this->mediaFormat,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            mediaPosition: (int) ($row['mediaPosition'] ?? 0),
            trackIndex: (int) ($row['trackIndex'] ?? 0),
            number: (string) ($row['number'] ?? ''),
            title: (string) ($row['title'] ?? ''),
            lengthMs: isset($row['lengthMs']) ? (int) $row['lengthMs'] : null,
            recordingId: (string) ($row['recordingId'] ?? ''),
            matchScore: (float) ($row['matchScore'] ?? 0),
            isBestMatch: (bool) ($row['isBestMatch'] ?? false),
            mediaFormat: $row['mediaFormat'] ?? null,
        );
    }
}
