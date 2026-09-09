<?php

namespace App\Services\Tidal;

use App\Exceptions\TidalException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     *
     * @throws TidalException when there are no credentials, or they are rejected
     */
    public function fetch(string $path, array $query = []): TidalResult
    {
        if (! $this->configured()) {
            throw TidalException::notConfigured();
        }

        // Applied here so no endpoint can omit it.
        $query['countryCode'] ??= (string) config('services.tidal.country', 'US');

        $result = $this->send($path, $query);

        // A 401 after a successful token fetch means the cached token went stale early.
        // Drop it and retry once; a second 401 is a real credential problem.
        if ($result->status === 401) {
            $this->forgetToken();

            $result = $this->send($path, $query);
        }

        // Twice is not staleness. This used to return null with no log line at all, so a
        // bearer Tidal will never accept looked exactly like a timeout. Thrown here so every
        // caller inherits the diagnosis.
        if ($result->status === 401) {
            Log::error('Tidal rejected a freshly minted token', [
                'path' => $path,
                ...$result->context(),
            ]);

            throw TidalException::bearerRejected(401, $result->detail);
        }

        return $result;
    }

    /** Whether a token is already cached, so the probe and doctor can say minted vs reused. */
    public function hasCachedToken(): bool
    {
        return is_string(Cache::get(self::TOKEN_CACHE_KEY));
    }

    /** Drop the cached token, so the next call mints a fresh one. */
    public function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function send(string $path, array $query): TidalResult
    {
        $token = $this->token();

        try {
            $response = $this->http()->withToken($token)->get($path, $query);
        } catch (ConnectionException $e) {
            Log::warning('Tidal request failed to connect', ['path' => $path, 'error' => $e->getMessage()]);

            return TidalResult::unreachable($e->getMessage());
        }

        if ($response->successful()) {
            $decoded = $response->json();

            if (is_array($decoded)) {
                return TidalResult::document($decoded, $response->status());
            }

            // A 2xx that is not JSON points at something in FRONT of Tidal - a proxy error
            // page, a captive portal - not at Tidal.
            return TidalResult::rejected($response->status(), 'the response body was not a JSON document');
        }

        $detail = $response->json('errors.0.detail');
        $detail = is_string($detail) ? $detail : null;

        $context = ['path' => $path, 'status' => $response->status(), 'detail' => $detail];

        // 404 means "nothing there" and a lone 401 is recovered above, so neither warns.
        // Anything else means Minizo or Tidal is wrong: the 400 from Tidal moving this
        // endpoint sat at info for the whole outage. Two calls, not Log::log() - the job test
        // mocks the facade and rejects unexpected methods.
        in_array($response->status(), [401, 404], true)
            ? Log::info('Tidal returned an error', $context)
            : Log::warning('Tidal returned an error', $context);

        return TidalResult::rejected($response->status(), $detail);
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
            // Retried too: a 5xx here otherwise kills every search and queued sync outright.
            $response = $this->withRetries(
                Http::asForm()->timeout((int) config('services.tidal.timeout', 15))
            )->post((string) config('services.tidal.token_uri'), [
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
        return $this->withRetries(
            Http::baseUrl((string) config('services.tidal.base_uri'))
                ->timeout((int) config('services.tidal.timeout', 15))
                // Retries multiply the read timeout, so a dead host must fail on connect
                // rather than spend the whole budget. A slow-but-alive one keeps TIDAL_TIMEOUT.
                ->connectTimeout((int) config('services.tidal.connect_timeout', 5))
                // Tidal v2 is JSON:API, and it is strict about the media type.
                ->withHeaders(['Accept' => 'application/vnd.api+json'])
        );
    }

    /** The retry policy both Tidal calls share. */
    private function withRetries(PendingRequest $request): PendingRequest
    {
        $tries = max(1, (int) config('services.tidal.retries', 3));
        $base = max(50, (int) config('services.tidal.retry_delay', 200));

        return $request->retry(
            times: $tries,
            sleepMilliseconds: function (int $attempt, Throwable $e) use ($base): int {
                // Retry-After may be an HTTP-date; (int) of one is 0, so it falls back to
                // the doubling delay. Capped so a Retry-After of 600 cannot park a request.
                $after = $e instanceof RequestException
                    ? (int) $e->response->header('Retry-After')
                    : 0;

                return $after > 0 ? min($after, 5) * 1000 : $base * (2 ** ($attempt - 1));
            },
            // Only 429 and 5xx mean "ask again". A 400/401/403/404 is a decision Tidal
            // repeats identically, so retrying it just turns a 15s failure into a 45s one.
            when: fn (Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException
                    && ($e->response->status() === 429 || $e->response->serverError())),
            // false: send() reads the status off the RESPONSE, and a thrown RequestException
            // would replace Tidal's errors.0.detail with Guzzle's generic message.
            throw: false,
        );
    }
}
