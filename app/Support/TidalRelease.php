<?php

namespace App\Support;

use App\Enums\ReleaseType;
use Carbon\CarbonImmutable;

final readonly class TidalRelease
{
    /** One release as Tidal describes it. */
    public function __construct(
        public string $providerId,
        public string $title,
        public ?ReleaseType $type = null,
        public ?CarbonImmutable $releasedOn = null,
        public ?string $coverUrl = null,
        public ?string $link = null,
    ) {}

    /** Whether this is old enough that importing it would be back-catalogue noise. */
    public function isWithinBackfillWindow(): bool
    {
        if ($this->releasedOn === null) {
            return true;
        }

        $days = (int) config('minizo.feed.backfill_days', 365);

        return $this->releasedOn->greaterThanOrEqualTo(CarbonImmutable::now()->subDays($days));
    }

    /** A key that is equal for two pressings of the same release. */
    public function variantKey(): string
    {
        return implode('|', [
            mb_strtolower(trim($this->title)),
            // ?? handles a null type; the nullsafe operator would be redundant beside it.
            $this->type->value ?? '',
            $this->releasedOn?->toDateString() ?? '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'provider_id' => $this->providerId,
            'title' => $this->title,
            'release_type' => $this->type?->value,
            'released_on' => $this->releasedOn?->toDateString(),
            'cover_url' => $this->coverUrl,
            'link' => $this->link,
        ];
    }
}
