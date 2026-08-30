<?php

namespace App\Services\Tidal;

use App\Exceptions\TidalException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TidalClient
{
    private const TOKEN_CACHE_KEY = 'minizo:tidal:token';

    /** Whether credentials exist at all. */
    public function configured(): bool
    {
        return filled(config('services.tidal.client_id'))
            && filled(config('services.tidal.client_secret'));
    }

    /**
     * A GET against the v2 API, with the country code and auth applied.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null null on any transport or server failure
     *
     * @throws TidalException when there are no credentials, or they are rejected
     */
    public function get(string $path, array $query = []): ?array
    {
        if (! $this->configured()) {
            throw TidalException::notConfigured();
        }

        // Applied here so no endpoint can omit it.
        $query['countryCode'] ??= (string) config('services.tidal.country', 'US');

        $response = $this->send($path, $query);

        // A 401 after a successful token fetch means the cached token went stale early.
        // Drop it and retry once; a second 401 is a real credential problem.
        if ($response === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);

            $response = $this->send($path, $query);
        }

        return is_array($response) ? $response : null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|int|null The decoded body, 401 to signal a retry, or
     *                                       null for anything else that failed.
     */
    private function send(string $path, array $query): array|int|null
    {
        $token = $this->token();

        try {
            $response = $this->http()->withToken($token)->get($path, $query);
        } catch (ConnectionException $e) {
            Log::warning('Tidal request failed to connect', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->status() === 401) {
            return 401;
        }

        if ($response->successful()) {
            $decoded = $response->json();

            return is_array($decoded) ? $decoded : null;
        }

        // A 404 is a normal answer here (an artist with no albums), so it logs at info.
        Log::info('Tidal returned an error', [
            'path' => $path,
            'status' => $response->status(),
            // The errors array is JSON:API's, and its `detail` is the only part worth
            // keeping; the rest is category metadata.
            'detail' => $response->json('errors.0.detail'),
        ]);

        return null;
    }

    /**
     * A bearer token, from cache when possible.
     *
     * @throws TidalException
     */
    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.tidal.timeout', 15))
                ->post((string) config('services.tidal.token_uri'), [
                    'grant_type' => 'client_credentials',
                    'client_id' => (string) config('services.tidal.client_id'),
                    'client_secret' => (string) config('services.tidal.client_secret'),
                ]);
        } catch (ConnectionException $e) {
            throw TidalException::authenticationFailed($e->getMessage());
        }

        if (! $response->successful()) {
            // The token endpoint answers in OAuth's error shape, not JSON:API's:
            // {"error":"…","error_description":"…"}.
            throw TidalException::authenticationFailed(
                (string) ($response->json('error_description') ?? $response->json('error') ?? 'HTTP '.$response->status())
            );
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw TidalException::authenticationFailed('the token response contained no access_token');
        }

        // Cached to just short of its lifetime; without the margin a token is
        // occasionally used at the instant it expires.
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $expiresIn - 60));

        return $token;
    }

    /** The configured HTTP client, with the bearer token applied. */
    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('services.tidal.base_uri'))
            ->timeout((int) config('services.tidal.timeout', 15))
            // Tidal v2 is JSON:API, and it is strict about the media type.
            ->withHeaders(['Accept' => 'application/vnd.api+json']);
    }
}
