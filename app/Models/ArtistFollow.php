<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property CarbonImmutable|null $last_viewed_at
 */
class ArtistFollow extends Pivot
{
    protected $table = 'artist_follows';

    // The table has its own id, so it is not a composite-key pivot.
    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_viewed_at' => 'datetime',
        ];
    }
}
