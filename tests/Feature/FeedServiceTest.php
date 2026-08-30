<?php

namespace Tests\Feature;

use App\Enums\ReleaseType;
use App\Exceptions\TidalException;
use App\Jobs\SyncArtistReleasesJob;
use App\Models\Artist;
use App\Models\ArtistRelease;
use App\Models\User;
use App\Services\Tidal\FeedService;
use App\Support\TidalArtist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ReadsFixtures;
use Tests\TestCase;

/** Following artists and importing releases. */
class FeedServiceTest extends TestCase
{
    use ReadsFixtures, RefreshDatabase;

    private FeedService $feed;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tidal.client_id' => 'test-id',
            'services.tidal.client_secret' => 'test-secret',
            'services.tidal.country' => 'US',
        ]);

        Cache::flush();
        RateLimiter::clear('tidal-search:1');

        $this->feed = app(FeedService::class);
    }

    private function fakeTidal(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/v2/artists/*/relationships/albums*' => Http::response($this->tidalFixture('artist-releases')),
            // The sync also refreshes the artist's own picture; see FeedService::importReleases.
            'openapi.tidal.com/v2/artists/*' => Http::response($this->tidalFixture('artist-lookup')),
            'openapi.tidal.com/*' => Http::response($this->tidalFixture('artist-search')),
        ]);
    }

    // ---------------------------------------------------------------------- search

    #[Test]
    public function it_searches_and_rate_limits_per_user(): void
    {
        $this->fakeTidal();

        $user = User::factory()->create();

        config(['minizo.feed.user_search_rate_limit' => 2]);

        $this->assertCount(20, $this->feed->search($user, 'Anitta'));
        $this->assertCount(20, $this->feed->search($user, 'Anitta 2'));

        // The limit is about fairness, not Tidal's tolerance: the token and request budget
        // are instance-wide, so one person holding a key down would spend everyone's.
        $this->expectException(TidalException::class);
        $this->feed->search($user, 'Anitta 3');
    }

    #[Test]
    public function one_users_searches_do_not_limit_another(): void
    {
        $this->fakeTidal();

        config(['minizo.feed.user_search_rate_limit' => 1]);

        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->feed->search($a, 'x');

        // Keyed per user, so a busy admin cannot lock everyone else out.
        $this->assertCount(20, $this->feed->search($b, 'y'));
    }

    // ---------------------------------------------------------------------- follow

    #[Test]
    public function following_creates_the_artist_and_queues_a_sync(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $artist = $this->feed->follow($user, new TidalArtist(
            providerId: '4906194',
            name: 'Anitta',
            imageUrl: 'https://resources.tidal.com/images/a/b/c/320x320.jpg',
            popularity: 87,
        ));

        $this->assertDatabaseHas('artists', [
            'provider' => 'tidal',
            'provider_id' => '4906194',
            'name' => 'Anitta',
            'name_key' => 'anitta',
        ]);

        $this->assertTrue($user->followedArtists()->whereKey($artist->getKey())->exists());

        // Queued, not inline. Following should feel instantaneous, and a deep catalogue is up
        // to three paginated requests against a rate-limited API.
        Queue::assertPushed(SyncArtistReleasesJob::class);
    }

    #[Test]
    public function following_twice_is_idempotent_and_does_not_mark_everything_unread(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $tidalArtist = new TidalArtist(providerId: '1', name: 'Anitta');

        $artist = $this->feed->follow($user, $tidalArtist);
        $this->feed->markViewed($user);

        $viewedAt = DB::table('artist_follows')->where('user_id', $user->id)->value('last_viewed_at');
        $this->assertNotNull($viewedAt);

        $this->travel(5)->minutes();
        $this->feed->follow($user, $tidalArtist);

        // syncWithoutDetaching leaves last_viewed_at alone. A reset would make a
        // double-clicked Follow button silently mark the whole feed unread again.
        $this->assertSame(
            $viewedAt,
            DB::table('artist_follows')->where('user_id', $user->id)->value('last_viewed_at'),
        );
        $this->assertSame(1, $user->followedArtists()->count());
    }

    #[Test]
    public function a_second_follower_does_not_trigger_a_refetch(): void
    {
        Queue::fake();

        $artist = Artist::factory()->create(['provider_id' => '1']);
        ArtistRelease::factory()->for($artist)->create();

        $this->feed->follow(User::factory()->create(), new TidalArtist(providerId: '1', name: $artist->name));

        // Releases already stored, so there is nothing to fetch. Re-importing a catalogue
        // because a second person clicked Follow would spend requests recreating rows.
        Queue::assertNotPushed(SyncArtistReleasesJob::class);
    }

    #[Test]
    public function following_refreshes_a_stale_image_url(): void
    {
        Queue::fake();

        $artist = Artist::factory()->create(['provider_id' => '1', 'image_url' => 'https://old.example/x.jpg']);

        $this->feed->follow(User::factory()->create(), new TidalArtist(
            providerId: '1',
            name: 'Anitta',
            imageUrl: 'https://resources.tidal.com/images/new/320x320.jpg',
        ));

        // A picture URL is a signed CDN link that eventually stops resolving.
        $this->assertStringContainsString('new', (string) $artist->fresh()?->image_url);
    }

    #[Test]
    public function unfollowing_leaves_the_artist_and_releases_behind(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $artist = Artist::factory()->create();
        ArtistRelease::factory()->for($artist)->create();
        $user->followedArtists()->attach($artist);

        $this->feed->unfollow($user, $artist);

        $this->assertFalse($user->followedArtists()->exists());

        // Someone else may follow them, and re-importing a catalogue because one person
        // changed their mind would spend requests recreating rows we just deleted.
        $this->assertModelExists($artist);
        $this->assertSame(1, $artist->releases()->count());
    }

    // ---------------------------------------------------------------------- import

    #[Test]
    public function it_imports_an_artists_recent_releases(): void
    {
        $this->fakeTidal();

        $artist = Artist::factory()->create(['provider_id' => '4906194']);

        $imported = $this->feed->importReleases($artist);

        // Nine, from the twenty albums the real response carries: Tidal lists regional
        // pressings as separate albums, and eleven of the twenty are variants of another.
        $this->assertSame(9, $imported);
        $this->assertSame(9, $artist->releases()->count());
        $this->assertDatabaseHas('artist_releases', ['title' => 'LOCA']);
    }

    #[Test]
    public function it_skips_releases_outside_the_backfill_window(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            // Authored rather than captured: the real fixture happens to contain only recent
            // releases, and a window this test is about needs something on each side of it.
            'openapi.tidal.com/v2/artists/*/relationships/albums*' => Http::response(['data' => [
                ['type' => 'albums', 'id' => '1', 'attributes' => [
                    'title' => 'This Year', 'type' => 'ALBUM', 'releaseDate' => now()->subMonth()->toDateString(),
                ]],
                ['type' => 'albums', 'id' => '2', 'attributes' => [
                    'title' => 'Ancient History', 'type' => 'ALBUM', 'releaseDate' => now()->subYears(7)->toDateString(),
                ]],
            ]]),
        ]);

        $artist = Artist::factory()->create(['provider_id' => '4906194']);

        /*
         * Following an artist with a thirty-year career would otherwise import everything and
         * flag it all new, burying the one release the person wanted to hear about.
         */
        $this->assertSame(1, $this->feed->importReleases($artist));
        $this->assertDatabaseHas('artist_releases', ['title' => 'This Year']);
        $this->assertDatabaseMissing('artist_releases', ['title' => 'Ancient History']);
    }

    #[Test]
    public function an_undated_release_is_kept(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/v2/artists/*/relationships/albums*' => Http::response(['data' => [
                ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'Untitled Pre-release', 'type' => 'SINGLE']],
            ]]),
        ]);

        $artist = Artist::factory()->create(['provider_id' => '4906194']);
        $this->feed->importReleases($artist);

        // Tidal omits the date on some pre-release and regional entries, and dropping those
        // would silently lose real new releases - the exact failure this feature prevents.
        $this->assertDatabaseHas('artist_releases', [
            'title' => 'Untitled Pre-release',
            'released_on' => null,
        ]);
    }

    #[Test]
    public function regional_pressings_are_imported_once(): void
    {
        $this->fakeTidal();

        $artist = Artist::factory()->create(['provider_id' => '4906194']);
        $this->feed->importReleases($artist);

        /*
         * The real response lists "Goals (FIFA World Cup 2026™)" three times - three ids, three
         * barcodes, identical title, date and duration, and nothing a listener could use to tell
         * them apart. Three rows would read as a bug in the feed.
         */
        $this->assertSame(
            1,
            $artist->releases()->where('title', 'like', 'Goals%')->count(),
        );
    }

    #[Test]
    public function a_sync_refreshes_the_artists_own_picture(): void
    {
        $this->fakeTidal();

        $artist = Artist::factory()->create(['provider_id' => '4906194', 'image_url' => null]);

        $this->feed->importReleases($artist);

        // A Tidal image URL is a signed CDN link that expires, and follow() is the only other
        // place one is written. Without this refresh a followed artist's picture is lost for
        // good, and rows created before the profileArt include never get one at all.
        $this->assertStringEndsWith('/320x320.jpg', (string) $artist->fresh()?->image_url);
    }

    #[Test]
    public function a_failed_artist_lookup_does_not_stop_the_release_import(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/v2/artists/*/relationships/albums*' => Http::response($this->tidalFixture('artist-releases')),
            'openapi.tidal.com/v2/artists/*' => Http::response(['errors' => [['detail' => 'gone']]], 404),
        ]);

        $artist = Artist::factory()->create(['provider_id' => '4906194']);

        // The releases are what the sync is for; a missing picture falls back to the
        // generated tile.
        $this->assertSame(9, $this->feed->importReleases($artist));
    }

    #[Test]
    public function importing_stamps_last_synced_at_even_when_nothing_is_new(): void
    {
        $this->fakeTidal();

        $artist = Artist::factory()->create(['provider_id' => '4906194', 'last_synced_at' => null]);

        $this->feed->importReleases($artist);
        $this->assertNotNull($artist->fresh()?->last_synced_at);

        Cache::flush();
        $this->travel(1)->hour();

        // Zero imported, but the stamp still moves: the claim is "we asked recently", so a
        // quiet artist is not re-queried every few minutes.
        $this->assertSame(0, $this->feed->importReleases($artist));
        $this->assertTrue($artist->fresh()?->last_synced_at->greaterThan(now()->subMinute()));
    }

    #[Test]
    public function a_re_import_updates_a_release_without_re_stamping_first_seen_at(): void
    {
        $this->fakeTidal();

        $artist = Artist::factory()->create(['provider_id' => '4906194']);
        $this->feed->importReleases($artist);

        $release = ArtistRelease::where('provider_id', '539663607')->firstOrFail();
        $firstSeen = $release->first_seen_at;

        $release->forceFill(['title' => 'Stale Title'])->save();

        Cache::flush();
        $this->travel(30)->days();
        $this->feed->importReleases($artist);

        $release->refresh();

        $this->assertSame('LOCA', $release->title, 'a corrected title is picked up');

        /*
         * first_seen_at is what "new" is computed from. Re-stamping it because a title was
         * corrected or a cover URL rotated would pop a months-old release back to the top of
         * everyone's feed.
         */
        $this->assertTrue($firstSeen->equalTo($release->first_seen_at));
    }

    #[Test]
    public function importing_twice_does_not_duplicate_releases(): void
    {
        $this->fakeTidal();

        $artist = Artist::factory()->create(['provider_id' => '4906194']);

        $this->feed->importReleases($artist);
        Cache::flush();
        $this->feed->importReleases($artist);

        // The unique index on (artist_id, provider_id) is what makes this idempotent.
        $this->assertSame(9, $artist->releases()->count());
    }

    #[Test]
    public function it_maps_release_types(): void
    {
        $this->fakeTidal();

        $artist = Artist::factory()->create(['provider_id' => '4906194']);
        $this->feed->importReleases($artist);

        $this->assertSame(
            ReleaseType::Single,
            ArtistRelease::where('provider_id', '539663607')->firstOrFail()->release_type,
        );
        $this->assertSame(
            ReleaseType::Album,
            ArtistRelease::where('provider_id', '545598782')->firstOrFail()->release_type,
        );
    }

    #[Test]
    public function it_stores_cover_art_and_a_tidal_link(): void
    {
        $this->fakeTidal();

        $artist = Artist::factory()->create(['provider_id' => '4906194']);
        $this->feed->importReleases($artist);

        $release = ArtistRelease::where('provider_id', '539663607')->firstOrFail();

        $this->assertStringEndsWith('/320x320.jpg', (string) $release->cover_url);
        $this->assertStringStartsWith('https://tidal.com/browse/album/', (string) $release->link);
    }

    // ------------------------------------------------------------------------ feed

    #[Test]
    public function the_feed_lists_followed_artists_alphabetically_with_recent_releases_first(): void
    {
        $zed = Artist::factory()->named('Zed')->create();
        $abba = Artist::factory()->named('Abba')->create();

        $old = ArtistRelease::factory()->for($abba)->create(['released_on' => '2026-01-01']);
        $new = ArtistRelease::factory()->for($abba)->create(['released_on' => '2026-07-01']);

        $user = User::factory()->create();
        $user->followedArtists()->attach([$abba->id, $zed->id]);

        $feed = $this->feed->feedFor($user);

        $this->assertSame(['Abba', 'Zed'], $feed->pluck('name')->all());
        $this->assertSame([$new->id, $old->id], $feed->first()->releases->pluck('id')->all());
    }

    #[Test]
    public function undated_releases_sort_after_dated_ones(): void
    {
        $artist = Artist::factory()->create();
        $undated = ArtistRelease::factory()->for($artist)->undated()->create();
        $dated = ArtistRelease::factory()->for($artist)->create(['released_on' => '2020-01-01']);

        $user = User::factory()->create();
        $user->followedArtists()->attach($artist);

        // Without the second sort key these would cluster arbitrarily at one end.
        $this->assertSame(
            [$dated->id, $undated->id],
            $this->feed->feedFor($user)->first()->releases->pluck('id')->all(),
        );
    }

    #[Test]
    public function the_feed_is_capped_per_artist(): void
    {
        config(['minizo.feed.releases_per_artist' => 2]);

        $artist = Artist::factory()->create();
        ArtistRelease::factory()->for($artist)->count(5)->create();

        $user = User::factory()->create();
        $user->followedArtists()->attach($artist);

        $this->assertCount(2, $this->feed->feedFor($user)->first()->releases);
    }

    #[Test]
    public function it_reports_what_was_new_then_marks_the_feed_viewed(): void
    {
        $artist = Artist::factory()->create();
        $fresh = ArtistRelease::factory()->for($artist)->justArrived()->create();
        $stale = ArtistRelease::factory()->for($artist)->old()->create();

        $user = User::factory()->create();
        $user->followedArtists()->attach($artist);

        // Read first, stamp second. Stamping on render instead would mean the badges were
        // never visible on the load that earned them.
        $newIds = $this->feed->markViewed($user);

        $this->assertContains($fresh->id, $newIds);
        $this->assertNotContains($stale->id, $newIds, 'older than new_for_days is never new');

        $this->assertSame([], $this->feed->markViewed($user), 'nothing is new on the second visit');
    }

    #[Test]
    public function new_is_per_user_not_global(): void
    {
        $artist = Artist::factory()->create();
        $release = ArtistRelease::factory()->for($artist)->justArrived()->create();

        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->followedArtists()->attach($artist);
        $bob->followedArtists()->attach($artist);

        $this->feed->markViewed($alice);

        /*
         * This is legacy defect #1, deleted rather than fixed: a single global `seen` column
         * meant the first person to open the feed marked everything read for everybody. "New"
         * is now derived from first_seen_at against each user's own last_viewed_at.
         */
        $this->assertContains($release->id, $this->feed->markViewed($bob));
    }

    #[Test]
    public function a_release_stays_new_for_a_few_days_after_being_seen(): void
    {
        config(['minizo.feed.new_for_days' => 14]);

        $artist = Artist::factory()->create();
        $user = User::factory()->create();
        $user->followedArtists()->attach($artist);

        // Glance at the feed on Monday…
        $this->feed->markViewed($user);

        $this->travel(1)->day();
        $release = ArtistRelease::factory()->for($artist)->justArrived()->create();

        $this->travel(3)->days();

        // …and Tuesday's album is still flagged on Friday.
        $this->assertContains($release->id, $this->feed->markViewed($user));
    }

    // ----------------------------------------------------------------- scheduling

    #[Test]
    public function it_queues_stale_artists_newest_follow_first(): void
    {
        Queue::fake();

        $follower = User::factory()->create();

        $neverSynced = Artist::factory()->create(['last_synced_at' => null]);
        $stale = Artist::factory()->stale()->create();
        $fresh = Artist::factory()->synced()->create();

        foreach ([$neverSynced, $stale, $fresh] as $artist) {
            $follower->followedArtists()->attach($artist);
        }

        $this->assertSame(2, $this->feed->queueStaleSyncs());

        Queue::assertPushed(SyncArtistReleasesJob::class, 2);

        // Null last_synced_at sorts first: the person who just clicked Follow is the one
        // waiting for a result.
        Queue::assertPushed(
            fn (SyncArtistReleasesJob $job): bool => $job->artist->is($neverSynced),
        );
    }

    #[Test]
    public function an_artist_nobody_follows_is_never_synced(): void
    {
        Queue::fake();

        Artist::factory()->create(['last_synced_at' => null]);

        // Unfollowing does not delete the row - it may be shared - but it does stop us
        // spending requests on it.
        $this->assertSame(0, $this->feed->queueStaleSyncs());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_batch_size_caps_one_scheduled_tick(): void
    {
        Queue::fake();

        config(['minizo.feed.sync_batch' => 3]);

        $follower = User::factory()->create();
        $artists = Artist::factory()->count(8)->create(['last_synced_at' => null]);
        $follower->followedArtists()->attach($artists->pluck('id'));

        // A backlog that takes an hour to drain also delays the newly-followed artist
        // somebody is actually waiting on.
        $this->assertSame(3, $this->feed->queueStaleSyncs());
    }
}
