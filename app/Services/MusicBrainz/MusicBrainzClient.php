<?php

namespace App\Services\MusicBrainz;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MusicBrainzClient
{
    /** The lock/timestamp key the one-per-second gate coordinates through. */
    private const THROTTLE_KEY = 'minizo:musicbrainz:last-request';

    /**
     * A search or lookup, cached.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>|null null on any failure, so a caller can degrade
     *                                   instead of 500ing.
     */
    public function get(string $path, array $query = []): ?array
    {
        $query['fmt'] = 'json';

        $key = 'musicbrainz:'.sha1($path.'?'.http_build_query($query));
        $ttl = (int) config('minizo.musicbrainz.cache_ttl', 86400);

        // Cached before the throttle, so a repeat lookup does not wait its turn.
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request($path, $query);

        if ($response === null) {
            return null;
        }

        Cache::put($key, $response, $ttl);

        return $response;
    }

    /** Whether MusicBrainz is reachable in principle. */
    public function configured(): bool
    {
        return true;
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>|null
     */
    private function request(string $path, array $query): ?array
    {
        $retries = max(0, (int) config('minizo.musicbrainz.retries', 2));

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $this->awaitTurn();

            try {
                $response = $this->http()->get($path, $query);
            } catch (ConnectionException $e) {
                Log::warning('MusicBrainz request failed to connect', [
                    'path' => $path,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($response->successful()) {
                $decoded = $response->json();

                return is_array($decoded) ? $decoded : null;
            }

            // 503 is what MusicBrainz returns when it is rate-limiting rather than
            // when it is broken, so it is the one status worth retrying.
            if (! $this->shouldRetry($response)) {
                Log::info('MusicBrainz returned an error', [
                    'path' => $path,
                    'status' => $response->status(),
                ]);

                return null;
            }
        }

        return null;
    }

    /** Whether a response is worth another attempt. */
    private function shouldRetry(Response $response): bool
    {
        return $response->status() === 503 || $response->serverError();
    }

    /** The configured HTTP client. */
    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('services.musicbrainz.base_uri'))
            ->timeout((int) config('minizo.musicbrainz.timeout', 15))
            ->acceptJson()
            // Mandatory: MusicBrainz answers a request without an application name,
            // version and contact URL with a 503 that looks like rate limiting.
            ->withUserAgent($this->userAgent())
            ->when(
                filled($token = config('services.musicbrainz.token')),
                // Only raises the rate limit; the API is open without it.
                fn (PendingRequest $request) => $request->withToken((string) $token, 'Bearer'),
            );
    }

    /** The User-Agent MusicBrainz requires. */
    private function userAgent(): string
    {
        return (string) config('services.musicbrainz.user_agent');
    }

    /** Block until at least min_request_interval has passed since the last request. */
    private function awaitTurn(): void
    {
        $interval = max(0, (int) config('minizo.musicbrainz.min_request_interval', 1100)) / 1000;

        if ($interval <= 0) {
            return;
        }

        $lock = Cache::lock(self::THROTTLE_KEY.':lock', 10);

        // block() waits for the previous caller to finish its interval. Failing to
        // acquire is not worth aborting over - going slightly fast once is better
        // than dropping the user's search.
        if (! $lock->block(15, fn () => null)) {
            return;
        }

        try {
            $last = (float) Cache::get(self::THROTTLE_KEY, 0.0);
            $elapsed = microtime(true) - $last;

            if ($last > 0.0 && $elapsed < $interval) {
                usleep((int) round(($interval - $elapsed) * 1_000_000));
            }

            Cache::put(self::THROTTLE_KEY, microtime(true), 60);
        } finally {
            $lock->release();
        }
    }
}
