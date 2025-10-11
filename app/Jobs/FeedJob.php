<?php

namespace App\Jobs;

use App\Models\LastFmTrack;
use Illuminate\Support\Arr;
use App\Helper\LastFmHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class FeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly string $artist,
        private readonly string $artistId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(LastFmHelper $lastFmHelper): void
    {
        try {
            $page       = 1;
            $processed  = 0;

            Log::info('Import schedule triggered started for artist: ' . $this->artist);

            do {
                $topTracks = $lastFmHelper->getArtistTopTracks($this->artist, $page);

                if (Arr::Get($topTracks, 'error')) {
                    Log::error("Last.fm API error for artist {$this->artist}: " . $topTracks['message']);
                    return;
                }

                if (Arr::Get($topTracks, 'toptracks.track') === []) {
                    Log::warning("No tracks found for artist {$this->artist} on page {$page}");
                    break;
                }

                $metadata = Arr::get($topTracks, 'toptracks.@attr');
                $totalPages = (int) ($metadata['totalPages'] ?? 0);
                
                Log::info("Processing page {$page} of {$totalPages} for artist {$this->artist}");

                foreach (Arr::get($topTracks, 'toptracks.track', []) as $track)
                {
                    $mediumImage = null;
                    foreach (Arr::get($track, 'image', []) as $image) {
                        if (Arr::get($image, 'size') === 'medium') {
                            $mediumImage = Arr::get($image, '#text');
                            break;
                        }
                    }

                    LastFmTrack::firstOrCreate(
                        [
                            'artist_id'     => $this->artistId,
                            'track_name'    => Arr::get($track, 'name')
                        ],
                        [
                            'lastfm_url'    => Arr::get($track, 'url') ?? null,
                            'image_url'     => $mediumImage,
                            'seen'          => false
                        ]
                    );
                    $processed++;
                }

                $page++;
                
                // Avoid rate limiting
                sleep(1);
                
            } while ($page <= $totalPages);

            Log::info("Successfully processed {$processed} tracks for artist: {$this->artist} across {$totalPages} pages");
            
        } catch (\Exception $e) {
            Log::error("Error processing feed for artist {$this->artist}: " . $e->getMessage(), [
                'exception' => $e,
                'artist' => $this->artist
            ]);
            
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("FeedJob failed for artist {$this->artist}", [
            'exception' => $exception,
            'artist' => $this->artist
        ]);
    }
}