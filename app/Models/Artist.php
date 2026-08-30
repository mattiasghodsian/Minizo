<?php

namespace App\Models;

use App\Support\TidalArtist;
use Database\Factories\ArtistFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An artist somebody follows.
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_id
 * @property string $name
 * @property string $name_key
 * @property string|null $image_url
 * @property int|null $popularity
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Artist extends Model
{
    /** @use HasFactory<ArtistFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'popularity' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ArtistRelease, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(ArtistRelease::class);
    }

    /**
     * @return BelongsToMany<User, $this, ArtistFollow, 'pivot'>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'artist_follows')
            ->using(ArtistFollow::class)
            ->withPivot('last_viewed_at')
            ->withTimestamps();
    }

    /** Find or create the row for a Tidal search result. */
    public static function fromTidal(TidalArtist $artist): self
    {
        $existing = static::query()
            ->where('provider', 'tidal')
            ->where('provider_id', $artist->providerId)
            ->first();

        $attributes = [
            'provider' => 'tidal',
            'provider_id' => $artist->providerId,
            'name' => $artist->name,
            'name_key' => static::key($artist->name),
            'image_url' => $artist->imageUrl,
            'popularity' => $artist->popularity,
        ];

        if ($existing !== null) {
            // Refreshed on every follow, because a picture URL is a signed CDN link that
            // eventually stops resolving.
            $existing->forceFill($attributes)->save();

            return $existing;
        }

        $model = new self;
        $model->forceFill($attributes)->save();

        return $model;
    }

    /** The lowercased name used for lookups. */
    public static function key(string $name): string
    {
        return Str::limit(Str::lower(trim($name)), 191, '');
    }

    /**
     * Artists due a release refresh, least recently synced first.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDueForSync(Builder $query): void
    {
        $cutoff = now()->subMinutes((int) config('minizo.feed.resync_after_minutes', 360));

        $query
            // Only artists somebody still follows. Unfollowing does not delete the row -
            // it may be shared - but it does stop us spending requests on it.
            ->whereHas('followers')
            ->where(fn (Builder $query) => $query
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', $cutoff))
            ->orderByRaw('last_synced_at IS NULL DESC')
            ->orderBy('last_synced_at');
    }

    /** The artist page on tidal.com. */
    public function tidalUrl(): string
    {
        return 'https://tidal.com/browse/artist/'.$this->provider_id;
    }
}
