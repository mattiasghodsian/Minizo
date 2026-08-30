<?php

namespace Tests\Feature;

use App\Exceptions\TidalException;
use App\Services\Tidal\TidalCatalogue;
use App\Services\Tidal\TidalClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ReadsFixtures;
use Tests\TestCase;

/** The HTTP layer: token caching, countryCode injection, includes, pagination, caching. */
class TidalCatalogueTest extends TestCase
{
    use ReadsFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tidal.client_id' => 'test-id',
            'services.tidal.client_secret' => 'test-secret',
            'services.tidal.country' => 'SE',
        ]);

        Cache::flush();
    }

    private function fakeToken(int $expiresIn = 3600): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response([
                'access_token' => 'token-abc',
                'token_type' => 'Bearer',
                'expires_in' => $expiresIn,
            ]),
        ]);
    }

    // --------------------------------------------------------------- configuration

    #[Test]
    public function it_reports_itself_unconfigured_without_credentials(): void
    {
        config(['services.tidal.client_id' => null, 'services.tidal.client_secret' => null]);

        // The whole point: the Feed says so and the rest of the app is unaffected. The
        // legacy MusicBrainz service threw from its constructor and 500'd every library page.
        $this->assertFalse(app(TidalClient::class)->configured());
        $this->assertFalse(app(TidalCatalogue::class)->configured());
    }

    #[Test]
    public function an_unconfigured_search_throws_rather_than_calling_out(): void
    {
        config(['services.tidal.client_secret' => null]);

        Http::preventStrayRequests();

        $this->expectException(TidalException::class);

        app(TidalCatalogue::class)->searchArtists('Anitta');
    }

    // ---------------------------------------------------------------------- search

    #[Test]
    public function it_searches_artists_and_injects_the_country_code(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/*' => Http::response($this->tidalFixture('artist-search')),
        ]);

        $artists = app(TidalCatalogue::class)->searchArtists('Anitta');

        $this->assertCount(20, $artists);
        $this->assertSame('Anitta', $artists[0]->name);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'searchResults')) {
                return false;
            }

            // countryCode is applied by the client so no endpoint can omit it - and it also
            // decides which releases are visible, since availability is licensed per country.
            //
            // The include is the NESTED form. Plain `include=artists` returns artists with no
            // image attribute at all, so every result would fall back to a generated tile.
            return str_contains($request->url(), 'countryCode=SE')
                && str_contains($request->url(), 'include=artists.profileArt');
        });
    }

    #[Test]
    public function search_results_arrive_in_the_apis_relevance_order(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/*' => Http::response($this->tidalFixture('artist-search')),
        ]);

        $artists = app(TidalCatalogue::class)->searchArtists('Anitta');

        // The exact-match artist first. `included` in this real response is sorted by id, which
        // put Anitta seventh - so this holds only because the mapper follows the relationship.
        $this->assertSame('Anitta', $artists[0]->name);
    }

    #[Test]
    public function search_results_carry_artist_photos(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/*' => Http::response($this->tidalFixture('artist-search')),
        ]);

        $artists = app(TidalCatalogue::class)->searchArtists('Anitta');

        // End to end: the nested include is requested, the artworks resource is joined, and a
        // 320px square crop survives the allow-list.
        $this->assertStringStartsWith('https://resources.tidal.com/images/', (string) $artists[0]->imageUrl);
        $this->assertStringEndsWith('/320x320.jpg', (string) $artists[0]->imageUrl);
    }

    #[Test]
    public function the_query_goes_in_the_path_url_encoded(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/*' => Http::response(['data' => [], 'included' => []]),
        ]);

        // A slash in an artist name would otherwise change which endpoint is being called.
        app(TidalCatalogue::class)->searchArtists('AC/DC');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'searchResults/AC%2FDC'));
    }

    #[Test]
    public function an_empty_query_makes_no_request(): void
    {
        Http::preventStrayRequests();

        $this->assertSame([], app(TidalCatalogue::class)->searchArtists('   '));
    }

    #[Test]
    public function search_results_are_cached_and_rehydrate_intact(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/*' => Http::response($this->tidalFixture('artist-search')),
        ]);

        $catalogue = app(TidalCatalogue::class);

        $first = $catalogue->searchArtists('Anitta');
        $second = $catalogue->searchArtists('anitta   ');   // same key: trimmed, lowercased

        // One catalogue request, not two. At one request per search against a rate-limited
        // API, a retyped name is a real cost rather than a rounding error.
        Http::assertSentCount(2);   // token + one search

        $this->assertEquals($first, $second);
        $this->assertSame(81, $second[0]->popularity, 'the cached round-trip keeps every field');
    }

    #[Test]
    public function a_failed_search_throws_and_is_not_cached(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/*' => Http::response(['errors' => [['detail' => 'boom']]], 500),
        ]);

        try {
            app(TidalCatalogue::class)->searchArtists('Anitta');
            $this->fail('expected a TidalException');
        } catch (TidalException) {
            // Caching a failure would make one bad minute look like a permanently missing
            // artist for the whole TTL.
            $this->assertNull(Cache::get('minizo:tidal:search:'.sha1('anitta|SE')));
        }
    }

    // ----------------------------------------------------------------------- token

    #[Test]
    public function the_token_is_fetched_once_and_reused(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 'token-abc', 'expires_in' => 3600]),
            'openapi.tidal.com/*' => Http::response(['data' => [], 'included' => []]),
        ]);

        $catalogue = app(TidalCatalogue::class);
        $catalogue->searchArtists('one');
        $catalogue->searchArtists('two');

        // The reason TidalClient exists. Syncing twenty artists is twenty catalogue
        // requests; re-authenticating each time would make it forty.
        Http::assertSentCount(3);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'openapi.tidal.com')
            && $r->hasHeader('Authorization', 'Bearer token-abc'));
    }

    #[Test]
    public function the_token_is_cached_for_less_than_its_lifetime(): void
    {
        $this->fakeToken(expiresIn: 600);

        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 'token-abc', 'expires_in' => 600]),
            'openapi.tidal.com/*' => Http::response(['data' => []]),
        ]);

        app(TidalCatalogue::class)->searchArtists('x');

        // Tidal said 600s, so the cache holds it for 540 - one minute short.
        $this->travel(530)->seconds();
        $this->assertSame('token-abc', Cache::get('minizo:tidal:token'));

        /*
         * Gone by 550s, while Tidal would still accept it for another 50. That margin is the
         * point: a token cached for its exact lifetime is guaranteed to be used at the moment
         * it becomes invalid at least occasionally, producing a 401 that looks random.
         */
        $this->travel(20)->seconds();
        $this->assertNull(Cache::get('minizo:tidal:token'));
    }

    #[Test]
    public function a_rejected_credential_reports_the_oauth_error_description(): void
    {
        /*
         * This shape is verified against the live endpoint: posting without a client_id
         * returns {"error":"invalid_request","error_description":"…"} with HTTP 400. Note it
         * is OAuth's error shape, not JSON:API's - the catalogue endpoints use `errors[]`.
         */
        Http::fake([
            'auth.tidal.com/*' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed',
            ], 401),
        ]);

        $this->expectException(TidalException::class);
        $this->expectExceptionMessageMatches('/Client authentication failed/');

        app(TidalCatalogue::class)->searchArtists('Anitta');
    }

    #[Test]
    public function a_401_from_the_catalogue_drops_the_token_and_retries_once(): void
    {
        Http::fakeSequence('auth.tidal.com/*')
            ->push(['access_token' => 'stale', 'expires_in' => 3600])
            ->push(['access_token' => 'fresh', 'expires_in' => 3600]);

        Http::fakeSequence('openapi.tidal.com/*')
            ->push(['errors' => [['code' => 'UNAUTHORIZED']]], 401)
            ->push($this->tidalFixture('artist-search'));

        $artists = app(TidalCatalogue::class)->searchArtists('Anitta');

        // A 401 after a successful token fetch means the cached token went stale early -
        // revoked, or the clock skewed. One retry recovers; a second 401 is a real problem.
        $this->assertCount(20, $artists);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'openapi.tidal.com')
            && $r->hasHeader('Authorization', 'Bearer fresh'));
    }

    #[Test]
    public function it_sends_the_json_api_media_type(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/*' => Http::response(['data' => []]),
        ]);

        app(TidalCatalogue::class)->searchArtists('x');

        // Tidal v2 is JSON:API and is strict about this.
        Http::assertSent(fn (Request $r): bool => ! str_contains($r->url(), 'auth.tidal.com')
            && $r->hasHeader('Accept', 'application/vnd.api+json'));
    }

    // -------------------------------------------------------------------- releases

    #[Test]
    public function it_lists_an_artists_releases(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/v2/artists/*' => Http::response($this->tidalFixture('artist-releases')),
        ]);

        $releases = app(TidalCatalogue::class)->releasesFor('4906194');

        // Nine, from twenty albums in the response. Tidal lists regional pressings separately
        // - three ids for one FIFA single, two for one album - so more than half of an
        // unfiltered feed would be visible duplicates.
        $this->assertCount(9, $releases);
        $this->assertSame('LOCA', $releases[0]->title);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'artists/4906194/relationships/albums')
            // Nested, for the same reason search needs artists.profileArt: an album's artwork
            // is a related resource, and the relationship is named coverArt here - an artist's
            // is profileArt, and the two are not interchangeable.
            && str_contains($r->url(), 'include=albums.coverArt'));
    }

    #[Test]
    public function releases_carry_cover_art(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/v2/artists/*' => Http::response($this->tidalFixture('artist-releases')),
        ]);

        $releases = app(TidalCatalogue::class)->releasesFor('4906194');

        $this->assertStringEndsWith('/320x320.jpg', (string) $releases[0]->coverUrl);
    }

    #[Test]
    public function it_follows_the_next_link_and_carries_the_cursor(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
        ]);

        $second = $this->tidalFixture('artist-releases');
        $second['included'] = [[
            'type' => 'albums',
            'id' => '777',
            'attributes' => ['title' => 'From page two', 'type' => 'EP', 'releaseDate' => '2026-06-01'],
        ]];
        unset($second['links']['next']);   // last page

        Http::fakeSequence('openapi.tidal.com/v2/artists/*')
            ->push($this->tidalFixture('artist-releases'))
            ->push($second);

        $releases = app(TidalCatalogue::class)->releasesFor('4906194');

        $titles = array_map(fn ($r): string => $r->title, $releases);
        $this->assertContains('From page two', $titles);

        Http::assertSent(function (Request $request): bool {
            // The cursor is lifted out of links.next's query only; feeding the whole URL back as a
            // path would double the base URI. Note the bracketed name: parse_str turns
            // `page[cursor]` into a nested array, and a scalars-only filter drops it, so every
            // request asks for page one again.
            return str_contains(urldecode($request->url()), 'page[cursor]=3nI1Esi');
        });
    }

    #[Test]
    public function pagination_stops_at_max_pages(): void
    {
        config(['minizo.feed.max_pages' => 2]);

        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            // Always advertises another page, so only the cap can end the loop.
            'openapi.tidal.com/v2/artists/*' => Http::response($this->tidalFixture('artist-releases')),
        ]);

        app(TidalCatalogue::class)->releasesFor('4906194');

        // Not politeness: an artist with a deep catalogue could otherwise walk hundreds of
        // pages inside one queued job, and the backfill window discards almost all of it.
        Http::assertSentCount(3);   // token + 2 pages
    }

    #[Test]
    public function a_failure_partway_through_pagination_keeps_what_it_has(): void
    {
        Http::fake(['auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600])]);

        Http::fakeSequence('openapi.tidal.com/v2/artists/*')
            ->push($this->tidalFixture('artist-releases'))
            ->push(['errors' => [['detail' => 'gateway']]], 502);

        $releases = app(TidalCatalogue::class)->releasesFor('4906194');

        // A partial import is strictly better than none, and the next sync completes it.
        $this->assertCount(9, $releases);
    }

    #[Test]
    public function releases_are_deduplicated_across_pages(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            // The same document twice: a cursor that overlaps is a normal API behaviour, and
            // the same release must not be counted as two.
            'openapi.tidal.com/v2/artists/*' => Http::response($this->tidalFixture('artist-releases')),
        ]);

        config(['minizo.feed.max_pages' => 3]);

        $releases = app(TidalCatalogue::class)->releasesFor('4906194');

        $ids = array_map(fn ($r): string => $r->providerId, $releases);
        $this->assertSame($ids, array_unique($ids));
        $this->assertCount(9, $releases);
    }

    #[Test]
    public function releases_are_cached_and_rehydrate_with_types_and_dates(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/v2/artists/*' => Http::response($this->tidalFixture('artist-releases')),
        ]);

        $catalogue = app(TidalCatalogue::class);

        $first = $catalogue->releasesFor('4906194');
        $second = $catalogue->releasesFor('4906194');

        // The sync job and a page render can ask within seconds of each other.
        $this->assertEquals($first, $second);
        $this->assertSame('2026-07-10', $second[0]->releasedOn?->toDateString());
        $this->assertSame('single', $second[0]->type?->value);
        $this->assertStringEndsWith('/320x320.jpg', (string) $second[0]->coverUrl);
    }

    #[Test]
    public function a_null_release_date_survives_the_cache_round_trip(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/v2/artists/*' => Http::response(['data' => [
                ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'Undated', 'type' => 'SINGLE']],
            ]]),
        ]);

        $catalogue = app(TidalCatalogue::class);
        $catalogue->releasesFor('4906194');

        // Serialised as null and rehydrated as null, not as "now" - which would put an undated
        // pre-release at the top of every feed.
        $this->assertNull($catalogue->releasesFor('4906194')[0]->releasedOn);
    }

    #[Test]
    public function the_cache_key_includes_the_country(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/v2/artists/*' => Http::response($this->tidalFixture('artist-releases')),
        ]);

        app(TidalCatalogue::class)->releasesFor('4906194');

        // Availability is licensed per territory, so a cache shared across countries would
        // serve one region's catalogue to another.
        $this->assertNotNull(Cache::get('minizo:tidal:releases:4906194:SE'));
        $this->assertNull(Cache::get('minizo:tidal:releases:4906194:US'));
    }
}
