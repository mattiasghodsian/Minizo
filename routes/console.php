<?php
/**
 * Schedule Artisan commands and closures.
 * See: https://laravel.com/docs/12.x/scheduling
 */
use App\Jobs\ImportLastFmTracks;
use Illuminate\Support\Facades\Schedule;

/**
 * Import tracks from artist - LastFm
 */
Schedule::job(new ImportLastFmTracks())
    ->name('import-lastfm-tracks')
    ->everyOddHour()
    ->withoutOverlapping()
    ->onOneServer();