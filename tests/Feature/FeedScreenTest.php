<?php

namespace Tests\Feature;

use App\Jobs\SyncArtistReleasesJob;
use App\Models\Artist;
use App\Models\ArtistRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ReadsFixtures;
use Tests\TestCase;

class FeedScreenTest extends TestCase
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

    // ------------------------------------------------------------------- rendering

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->get(route('feed'))->assertRedirect(route('login'));
    }

    #[Test]
    public function any_user_may_open_it(): void
    {
        // No permission gate: following an artist affects nobody else.
        $this->actingAs(User::factory()->viewOnly()->create())
            ->get(route('feed'))
            ->assertOk();
    }

    #[Test]
    public function it_renders_followed_artists_and_their_releases(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->named('Anitta')->create();
        ArtistRelease::factory()->for($artist)->create(['title' => 'Funk Generation']);
        $user->followedArtists()->attach($artist);

        $this->actingAs($user)
            ->get(route('feed'))
            ->assertOk()
            ->assertSee('Anitta')
            ->assertSee('Funk Generation');
    }

    #[Test]
    public function it_offers_the_empty_state_when_nothing_is_followed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('feed'))
            ->assertSee('No artists followed yet', escape: false)
            // Tidal, never Last.fm.
            ->assertSee('search Tidal above', escape: false)
            ->assertDontSee('Last.fm');
    }

    #[Test]
    public function it_says_so_when_tidal_is_unconfigured(): void
    {
        config(['services.tidal.client_id' => null, 'services.tidal.client_secret' => null]);

        // Degrades rather than throwing - the legacy MusicBrainz service threw from its
        // constructor and 500'd every library page when its token was unset.
        $this->actingAs(User::factory()->create())
            ->get(route('feed'))
            ->assertOk()
            ->assertSee('TIDAL_CLIENT_ID')
            ->assertDontSee('Search Tidal for an artist');
    }

    #[Test]
    public function a_followed_artist_still_renders_when_tidal_is_unconfigured(): void
    {
        config(['services.tidal.client_id' => null, 'services.tidal.client_secret' => null]);

        $user = User::factory()->create();
        $artist = Artist::factory()->named('Anitta')->create();
        ArtistRelease::factory()->for($artist)->create(['title' => 'Funk Generation']);
        $user->followedArtists()->attach($artist);

        // Everything already stored is still worth showing.
        $this->actingAs($user)->get(route('feed'))->assertSee('Funk Generation');
    }

    #[Test]
    public function it_links_releases_to_tidal(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();
        ArtistRelease::factory()->for($artist)->create(['link' => 'https://tidal.com/browse/album/1']);
        $user->followedArtists()->attach($artist);

        $this->actingAs($user)
            ->get(route('feed'))
            ->assertSee('https://tidal.com/browse/album/1')
            ->assertSee('Tidal');
    }

    #[Test]
    public function it_links_releases_to_a_youtube_music_search(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->named('Anitta')->create();
        ArtistRelease::factory()->for($artist)->create(['title' => 'Funk Generation']);
        $user->followedArtists()->attach($artist);

        /*
         * The link that makes the Feed actionable. Tidal's own link confirms a release
         * exists; this is where you go to get it - search, copy the URL, paste it into the
         * Download screen.
         */
        $this->actingAs($user)
            ->get(route('feed'))
            ->assertSee('https://music.youtube.com/search?q=Anitta%20Funk%20Generation', escape: false)
            ->assertSee('YouTube Music');
    }

    #[Test]
    public function the_youtube_music_query_survives_awkward_titles(): void
    {
        $artist = Artist::factory()->named('Sofía Reyes')->create();

        $release = ArtistRelease::factory()->for($artist)->create([
            'title' => "Goals  (FIFA World Cup 2026™)\n",
        ]);

        // Whitespace is collapsed: a title carrying a newline would otherwise put %0A in the
        // middle of the query, which returns nothing at all.
        $url = $release->youtubeMusicUrl($artist->name);

        $this->assertStringStartsWith('https://music.youtube.com/search?q=', $url);
        $this->assertStringNotContainsString('%0A', $url);
        $this->assertSame(
            'Sofía Reyes Goals (FIFA World Cup 2026™)',
            rawurldecode(str_replace('https://music.youtube.com/search?q=', '', $url)),
        );
    }

    // ---------------------------------------------------------------------- search

    #[Test]
    public function it_searches_and_shows_results(): void
    {
        $this->fakeTidal();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::feed')
            ->set('query', 'Anitta')
            ->call('search')
            ->assertSet('searched', true)
            ->assertCount('results', 20)
            ->assertSee('Anitta')
            ->assertSee('+ Follow');
    }

    #[Test]
    public function an_empty_query_clears_rather_than_searching(): void
    {
        Http::preventStrayRequests();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::feed')
            ->set('query', '   ')
            ->call('search')
            ->assertSet('searched', false)
            ->assertSet('results', []);
    }

    #[Test]
    public function a_search_failure_is_shown_as_a_field_error(): void
    {
        Http::fake([
            'auth.tidal.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'openapi.tidal.com/*' => Http::response(['errors' => [['detail' => 'down']]], 503),
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test('pages::feed')
            ->set('query', 'Anitta')
            ->call('search')
            ->assertHasErrors('query')
            ->assertSet('searched', false);
    }

    #[Test]
    public function clearing_resets_the_search(): void
    {
        $this->fakeTidal();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::feed')
            ->set('query', 'Anitta')
            ->call('search')
            ->call('clearSearch')
            ->assertSet('query', '')
            ->assertSet('results', [])
            ->assertSet('searched', false);
    }

    #[Test]
    public function results_are_stored_as_primitives(): void
    {
        $this->fakeTidal();

        $component = Livewire::actingAs(User::factory()->create())
            ->test('pages::feed')
            ->set('query', 'Anitta')
            ->call('search');

        // A value object in a public Livewire property is serialised into the payload and
        // rehydrated from whatever the browser sent back. Arrays cross the wire, and
        // TidalArtist::fromArray() revalidates on the way in, including the image allow-list.
        foreach ($component->get('results') as $row) {
            $this->assertIsArray($row);
        }
    }

    // ---------------------------------------------------------------------- follow

    #[Test]
    public function following_a_result_stores_the_artist_and_shows_it_as_following(): void
    {
        Queue::fake();
        $this->fakeTidal();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::feed')
            ->set('query', 'Anitta')
            ->call('search')
            ->call('follow', '4906194')
            ->assertSee('✓ Following', escape: false);

        $this->assertDatabaseHas('artists', ['provider_id' => '4906194', 'name' => 'Anitta']);
        Queue::assertPushed(SyncArtistReleasesJob::class);
    }

    #[Test]
    public function following_an_id_that_was_not_in_the_results_is_rejected(): void
    {
        Queue::fake();
        $this->fakeTidal();

        /*
         * The id arrives from the browser, so it is re-checked against the results this
         * component actually produced. Without that, a hand-edited payload could create an
         * arbitrary artist row with an attacker-chosen provider id.
         */
        Livewire::actingAs(User::factory()->create())
            ->test('pages::feed')
            ->set('query', 'Anitta')
            ->call('search')
            ->call('follow', '999999999')
            ->assertStatus(404);

        $this->assertDatabaseMissing('artists', ['provider_id' => '999999999']);
    }

    #[Test]
    public function unfollowing_removes_the_artist_from_the_feed(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->named('Anitta')->create();
        $user->followedArtists()->attach($artist);

        Livewire::actingAs($user)
            ->test('pages::feed')
            ->call('unfollow', $artist->id)
            ->assertDontSee('Anitta');

        $this->assertFalse($user->followedArtists()->exists());
    }

    // --------------------------------------------------------------- new markers

    #[Test]
    public function a_release_seen_for_the_first_time_is_marked_new(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();
        ArtistRelease::factory()->for($artist)->justArrived()->create(['title' => 'Just Landed']);
        $user->followedArtists()->attach($artist);

        $this->actingAs($user)->get(route('feed'))->assertSee('New');
    }

    #[Test]
    public function the_new_marker_survives_the_render_that_clears_it(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();
        $release = ArtistRelease::factory()->for($artist)->justArrived()->create();
        $user->followedArtists()->attach($artist);

        /*
         * mount() both reads what was new and marks the feed viewed. Holding the ids in the
         * component is what lets this render show badges it has already cleared - recomputing
         * them would find nothing and the marker would never be visible at all.
         */
        $component = Livewire::actingAs($user)->test('pages::feed');

        $this->assertSame([$release->id], $component->get('newReleaseIds'));
        $this->assertNotNull(DB::table('artist_follows')->where('user_id', $user->id)->value('last_viewed_at'));
    }

    #[Test]
    public function a_second_visit_shows_no_marker(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();
        ArtistRelease::factory()->for($artist)->justArrived()->create();
        $user->followedArtists()->attach($artist);

        Livewire::actingAs($user)->test('pages::feed');

        $this->assertSame([], Livewire::actingAs($user)->test('pages::feed')->get('newReleaseIds'));
    }

    // ------------------------------------------------------------- admin preview

    #[Test]
    public function the_preview_row_is_admin_only(): void
    {
        User::factory()->count(2)->create();

        $this->actingAs(User::factory()->create())
            ->get(route('feed'))
            ->assertDontSee('Feed for')
            ->assertDontSee('admin preview');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('feed'))
            ->assertSee('Feed for')
            ->assertSee('admin preview', escape: false);
    }

    #[Test]
    public function an_admin_can_preview_another_users_feed(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create(['name' => 'Bob']);

        $artist = Artist::factory()->named('Bobs Artist')->create();
        $other->followedArtists()->attach($artist);

        Livewire::actingAs($admin)
            ->test('pages::feed')
            ->assertDontSee('Bobs Artist')
            ->call('preview', $other->id)
            ->assertSee('Bobs Artist');
    }

    #[Test]
    public function previewing_does_not_clear_the_other_users_new_markers(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        $artist = Artist::factory()->create();
        ArtistRelease::factory()->for($artist)->justArrived()->create();
        $other->followedArtists()->attach($artist);

        Livewire::actingAs($admin)->test('pages::feed')->call('preview', $other->id);

        /*
         * A read-only preview must not have side effects on the person being previewed. An
         * admin glancing at someone's feed would otherwise clear their badges for them, and
         * the user would never know a release had arrived.
         */
        $this->assertNull(
            DB::table('artist_follows')->where('user_id', $other->id)->value('last_viewed_at'),
        );
    }

    #[Test]
    public function previewing_hides_the_unfollow_control(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        $artist = Artist::factory()->named('Bobs Artist')->create();
        $other->followedArtists()->attach($artist);

        // Unfollowing on someone else's behalf is not what a preview is for.
        Livewire::actingAs($admin)
            ->test('pages::feed')
            ->call('preview', $other->id)
            ->assertSee('Bobs Artist')
            ->assertDontSee('Unfollow');
    }

    #[Test]
    public function following_while_previewing_follows_yourself(): void
    {
        Queue::fake();
        $this->fakeTidal();

        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        Livewire::actingAs($admin)
            ->test('pages::feed')
            ->call('preview', $other->id)
            ->set('query', 'Anitta')
            ->call('search')
            ->call('follow', '4906194');

        // An admin inspecting a colleague's feed must not be able to subscribe them to things.
        $this->assertTrue($admin->followedArtists()->exists());
        $this->assertFalse($other->followedArtists()->exists());
    }

    #[Test]
    public function a_non_admin_cannot_preview_by_calling_the_action(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $artist = Artist::factory()->named('Bobs Artist')->create();
        $other->followedArtists()->attach($artist);

        // The pills are hidden for a non-admin, but hiding a control is not authorisation.
        Livewire::actingAs($user)
            ->test('pages::feed')
            ->call('preview', $other->id)
            ->assertForbidden();
    }

    #[Test]
    public function an_admin_previewing_an_unknown_id_falls_back_to_their_own_feed(): void
    {
        $admin = User::factory()->admin()->create();

        $mine = Artist::factory()->named('My Artist')->create();
        $admin->followedArtists()->attach($mine);

        // previewUserId arrives from the browser, so it is re-resolved against the
        // authoritative list rather than trusted as a user id.
        Livewire::actingAs($admin)
            ->test('pages::feed')
            ->set('previewUserId', 999_999)
            ->assertSee('My Artist');
    }
}
