<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LastFmArtist extends Model
{
    protected $table = 'lastfm_artists';

    protected $fillable = [
        'artist_name',
        'lastfm_url',
    ];

    /**
     * Get all tracks for this artist
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(LastFmTrack::class, 'artist_id');
    }

    /**
     * Get latest tracks for this artist
     */
    public function latestTracks()
    {
        return $this->tracks()->latest();
    }
}