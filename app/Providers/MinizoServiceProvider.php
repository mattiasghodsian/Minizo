<?php

namespace App\Providers;

use App\Services\Download\AudioDownloader;
use App\Services\Download\YoutubeDlOptionsFactory;
use App\Services\Download\YtDlpAudioDownloader;
use App\Support\LibraryCache;
use Illuminate\Support\ServiceProvider;
use YoutubeDl\YoutubeDl;

class MinizoServiceProvider extends ServiceProvider
{
    /** Bind the library services and their request-scoped cache. */
    public function register(): void
    {
        // A singleton so the sidebar, the policy check and the page body share one memo
        // of the folder list. The container is per-request, so this is request-scoped;
        // under Octane it would need resetting between requests.
        $this->app->singleton(LibraryCache::class);

        $this->registerDownloader();
    }

    /** The downloader, and the one binding subtlety worth spelling out. */
    private function registerDownloader(): void
    {
        $this->app->bind(AudioDownloader::class, fn ($app) => new YtDlpAudioDownloader(
            factory: fn (): YoutubeDl => new YoutubeDl,
            options: $app->make(YoutubeDlOptionsFactory::class),
        ));
    }
}
