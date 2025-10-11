<?php

namespace App\Jobs;

use App\Models\LastFmArtist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;

class ImportLastFmTracks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $artists = LastFmArtist::all();
        $count   = 0;

        foreach ($artists as $artist) {
            $delay = Carbon::now()->addMinutes(5 * $count);
            
            FeedJob::dispatch($artist->artist_name, $artist->id)
                  ->delay($delay);
            $count++;
        }
    }
}