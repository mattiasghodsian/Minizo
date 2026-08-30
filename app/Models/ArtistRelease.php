<?php

namespace App\Models;

use App\Enums\ReleaseType;
use Database\Factories\ArtistReleaseFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $artist_id
 * @property string $provider_id
 * @property string $title
 * @property ReleaseType|null $release_type
 * @property Carbon|null $released_on
 * @property string|null $cover_url
 * @property string|null $link
 * @property Carbon $first_seen_at
 * @property-read Artist $artist
 */
class ArtistRelease extends Model
{
    /** @use HasFactory<ArtistReleaseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'release_type' => ReleaseType::class,
            'released_on' => 'date',
            'first_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Artist, $this>
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /** Whether this is new to a given user. */
    public function isNewFor(?DateTimeInterface $lastViewedAt): bool
    {
        $window = now()->subDays((int) config('minizo.feed.new_for_days', 14));

        // Older than the window is never new, however long ago you last looked.
        if ($this->first_seen_at->lessThan($window)) {
            return false;
        }

        return $lastViewedAt === null || $this->first_seen_at->greaterThan($lastViewedAt);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        // Tidal returns no date on some pre-release and regional entries, so the
        // first_seen_at tiebreak keeps those from clustering at one end.
        $query->orderByRaw('released_on IS NULL')
            ->orderByDesc('released_on')
            ->orderByDesc('first_seen_at');
    }

    /** A YouTube Music search for this release. */
    public function youtubeMusicUrl(string $artistName): string
    {
        // Collapsed, because a title with a line break or doubled space in it produces a
        // query with %0A in the middle and no results at all.
        $query = preg_replace('/\s+/u', ' ', trim($artistName.' '.$this->title));

        return 'https://music.youtube.com/search?q='.rawurlencode((string) $query);
    }

    /** Release year, or null when Tidal gave no date. */
    public function yearLabel(): ?string
    {
        return $this->released_on?->format('Y');
    }

    /** "12 Mar 2026", or null. Used as the row's title attribute, so the exact date is available without spending a column on it. */
    public function dateLabel(): ?string
    {
        return $this->released_on?->format('j M Y');
    }
}
