<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LastFmTrack extends Model
{
    protected $table = 'lastfm_tracks';

    protected $fillable = [
        'artist_id',
        'track_name',
        'lastfm_url',
        'image_url',
        'known'
    ];

    protected $casts = [
        'known' => 'boolean'
    ];

    /**
     * Get the artist that owns the track
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(LastFmArtist::class, 'artist_id');
    }

    /**
     * Scope a query to only include unknown tracks
     */
    public function scopeUnknown($query)
    {
        return $query->where('known', false);
    }

    /**
     * Scope a query to only include known tracks
     */
    public function scopeKnown($query)
    {
        return $query->where('known', true);
    }
}