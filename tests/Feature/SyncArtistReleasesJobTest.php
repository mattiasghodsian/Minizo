<?php

namespace Tests\Feature;

use App\Jobs\SyncArtistReleasesJob;
use App\Models\Artist;
use App\Services\Tidal\FeedService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ReadsFixtures;
use Tests\TestCase;

class SyncArtistReleasesJobTest extends TestCase
{
    use ReadsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tidal.client_id' => 'test-id',
            'services.tidal.client_secret' => 'test-secret',
        ]);

        Cache::flush();
    }

    #[Test]
    public function it_imports_an_artists_releases(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/v2/artists/*/relationships/albums*' => Http::response($this->tidalFixture('artist-releases')),
            'openapi.tidal.com/*' => Http::response($this->tidalFixture('artist-lookup')),
        ]);

        $artist = Artist::factory()->create(['provider_id' => '4906194']);

        (new SyncArtistReleasesJob($artist))->handle(app(FeedService::class));

        // Nine from the twenty albums in the captured response; the rest are regional pressings
        // of one another.
        $this->assertSame(9, $artist->releases()->count());
        $this->assertNotNull($artist->fresh()?->last_synced_at);
    }

    #[Test]
    public function it_carries_rate_limiting_and_overlap_middleware(): void
    {
        $artist = Artist::factory()->create();

        $middleware = (new SyncArtistReleasesJob($artist))->middleware();

        // Rate limiting belongs in middleware, where a throttled job is released back to the
        // queue and the worker moves on. A sleep(1) inside the worker holds it instead.
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[1]);
    }

    #[Test]
    public function overlap_is_keyed_per_artist(): void
    {
        $a = Artist::factory()->create();
        $b = Artist::factory()->create();

        // Per-artist, not global: two different artists must be able to sync at once, or the
        // whole feature serialises behind whichever artist happens to be first.
        $keyOf = function (Artist $artist): string {
            /** @var WithoutOverlapping $middleware */
            $middleware = (new SyncArtistReleasesJob($artist))->middleware()[1];

            return $middleware->key;
        };

        $this->assertNotSame($keyOf($a), $keyOf($b));
        $this->assertSame((string) $a->getKey(), $keyOf($a));
    }

    #[Test]
    public function it_retries_for_an_hour_rather_than_a_fixed_number_of_attempts(): void
    {
        $job = new SyncArtistReleasesJob(Artist::factory()->create());

        // A window rather than a try count, because the failure being retried is usually a
        // rate limit or an outage - both of which are about elapsed time, not attempts.
        $this->assertEqualsWithDelta(
            now()->addHour()->getTimestamp(),
            $job->retryUntil()->getTimestamp(),
            5,
        );
    }

    #[Test]
    public function it_runs_on_the_configured_queue(): void
    {
        config(['minizo.feed.queue' => 'feed']);

        $this->assertSame('feed', (new SyncArtistReleasesJob(Artist::factory()->create()))->queue);
    }

    #[Test]
    public function a_deleted_artist_is_not_an_error(): void
    {
        Http::preventStrayRequests();

        $artist = Artist::factory()->create();
        $artist->delete();

        // An artist nobody follows any more, or a cascade from a deleted account.
        (new SyncArtistReleasesJob($artist))->handle(app(FeedService::class));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function missing_credentials_are_logged_not_re_thrown(): void
    {
        config(['services.tidal.client_id' => null, 'services.tidal.client_secret' => null]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'could not run'));

        /*
         * Not re-thrown: missing credentials and a rejected token will not fix themselves on
         * retry, and a failed_jobs row per artist per attempt would bury the one log line
         * that says what is actually wrong.
         */
        (new SyncArtistReleasesJob(Artist::factory()->create()))->handle(app(FeedService::class));
    }

    #[Test]
    public function a_tidal_outage_is_logged_not_re_thrown(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['error' => 'server_error'], 503),
        ]);

        Log::shouldReceive('warning')->once();

        (new SyncArtistReleasesJob(Artist::factory()->create()))->handle(app(FeedService::class));
    }

    #[Test]
    public function the_tidal_rate_limiter_is_registered(): void
    {
        // The RateLimited middleware names a limiter; an unregistered name is a silent
        // no-op, so the whole protection would be absent with nothing to notice.
        $this->assertNotNull(
            app(RateLimiter::class)->limiter('tidal'),
            'the "tidal" rate limiter must be defined for RateLimited middleware to do anything',
        );
    }
}
