<?php

namespace App\Services\MusicBrainz;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CoverArtArchive
{
    /** The front cover's URL, or null when there is none. */
    public function frontCoverUrl(string $releaseId): ?string
    {
        $key = 'coverart:'.$releaseId;

        $cached = Cache::get($key);

        if ($cached !== null) {
            // '' is the cached form of "checked, and there is none" - distinct from a
            // cache miss, which is what null means.
            return $cached === '' ? null : (string) $cached;
        }

        $url = $this->fetch($releaseId);

        Cache::put($key, $url ?? '', (int) config('minizo.musicbrainz.cache_ttl', 86400));

        return $url;
    }

    /** The front cover for a release, or null when there is none. */
    private function fetch(string $releaseId): ?string
    {
        try {
            $response = Http::baseUrl((string) config('services.musicbrainz.cover_art_uri'))
                ->timeout((int) config('minizo.musicbrainz.timeout', 15))
                ->withUserAgent((string) config('services.musicbrainz.user_agent'))
                ->acceptJson()
                // The archive answers with a 307 to the storage host.
                ->withOptions(['allow_redirects' => true])
                ->get('release/'.$releaseId);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $images = $response->json('images');

        if (! is_array($images)) {
            return null;
        }

        return $this->pickFront($images);
    }

    /**
     * @param  array<int, array<string, mixed>>  $images
     */
    private function pickFront(array $images): ?string
    {
        $front = null;

        foreach ($images as $image) {
            if (($image['front'] ?? false) === true) {
                $front = $image;

                break;
            }
        }

        // A release with art but nothing flagged "front" still has something worth
        // showing, and the first image is almost always the cover.
        $front ??= $images[0] ?? null;

        if (! is_array($front)) {
            return null;
        }

        // The 500px thumbnail rather than the original, which can be several megabytes
        // of scanned artwork going into a PICTURE block that ships with every download.
        $url = $front['thumbnails']['500']
            ?? $front['thumbnails']['large']
            ?? $front['image']
            ?? null;

        return $url !== null ? $this->https((string) $url) : null;
    }

    /** Cover Art Archive still returns http:// URLs in its JSON. */
    private function https(string $url): string
    {
        return Str::startsWith($url, 'http://')
            ? Str::replaceFirst('http://', 'https://', $url)
            : $url;
    }
}
