<?php

namespace Tests\Feature\Api;

use App\Enums\ReleaseType;
use App\Models\Artist;
use App\Models\ArtistRelease;
use App\Models\User;
use App\Support\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** GET /api/feed — the token-authenticated, read-only feed. */
class FeedApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'mnz_test-token';

    /** A user holding the test token, following one artist with one release. */
    private function subscriber(array $releaseAttributes = []): User
    {
        $user = User::factory()->withApiToken(self::TOKEN)->create();

        $artist = Artist::factory()->named('Boards of Canada')->create();

        ArtistRelease::factory()
            ->justArrived()
            ->type(ReleaseType::Album)
            ->create(['artist_id' => $artist->getKey(), ...$releaseAttributes]);

        $user->followedArtists()->attach($artist);

        return $user;
    }

    private function callFeed(string $token = self::TOKEN)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/feed');
    }

    // ---- authentication

    #[Test]
    public function it_rejects_a_request_with_no_token(): void
    {
        $this->getJson('/api/feed')->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_an_unknown_token_with_the_same_reply_as_a_missing_one(): void
    {
        // Identical messages, so a reply cannot be used to tell which strings are live.
        $missing = $this->getJson('/api/feed')->assertUnauthorized();
        $unknown = $this->callFeed('mnz_not-a-real-token')->assertUnauthorized();

        $this->assertSame($missing->json('message'), $unknown->json('message'));
        $this->assertSame('API token missing or invalid.', $unknown->json('message'));
    }

    #[Test]
    public function it_rejects_a_token_belonging_to_a_deactivated_account(): void
    {
        User::factory()->disabled()->withApiToken(self::TOKEN)->create();

        // EnsureUserIsActive cannot run on API routes; AuthenticateApiToken has to.
        $this->callFeed()->assertForbidden();
    }

    #[Test]
    public function it_is_not_reachable_with_a_session_cookie(): void
    {
        $user = User::factory()->create();

        // Proves the endpoint is outside the web group: a browser session is not a token.
        $this->actingAs($user)->getJson('/api/feed')->assertUnauthorized();
    }

    #[Test]
    public function it_updates_last_used_at(): void
    {
        $user = $this->subscriber();

        $this->assertNull($user->api_token_last_used_at);

        $this->callFeed()->assertOk();

        $this->assertNotNull($user->fresh()->api_token_last_used_at);
    }

    // ---- the payload

    #[Test]
    public function it_returns_the_authenticated_users_feed(): void
    {
        $this->subscriber();

        $other = User::factory()->create();
        $otherArtist = Artist::factory()->named('Someone Else')->create();
        $other->followedArtists()->attach($otherArtist);

        $response = $this->callFeed()->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Boards of Canada')
            ->assertJsonPath('data.0.provider', 'tidal')
            ->assertJsonPath('meta.artists', 1)
            ->assertJsonPath('meta.releases', 1);

        $this->assertStringNotContainsString('Someone Else', $response->getContent());
    }

    #[Test]
    public function every_release_carries_artist_cover_art_title_and_both_links(): void
    {
        $this->subscriber([
            'title' => "Tomorrow's Harvest",
            'cover_url' => 'https://resources.tidal.com/images/a41f/640x640.jpg',
            'link' => 'https://tidal.com/browse/album/295292817',
        ]);

        $this->callFeed()->assertOk()
            ->assertJsonPath('data.0.releases.0.title', "Tomorrow's Harvest")
            ->assertJsonPath('data.0.releases.0.artist', 'Boards of Canada')
            ->assertJsonPath('data.0.releases.0.cover_url', 'https://resources.tidal.com/images/a41f/640x640.jpg')
            ->assertJsonPath('data.0.releases.0.tidal_url', 'https://tidal.com/browse/album/295292817')
            ->assertJsonPath('data.0.releases.0.type', 'album')
            ->assertJsonPath('data.0.releases.0.type_label', 'Album')
            ->assertJsonPath(
                'data.0.releases.0.youtube_music_url',
                'https://music.youtube.com/search?q=Boards%20of%20Canada%20Tomorrow%27s%20Harvest',
            );
    }

    #[Test]
    public function it_falls_back_to_an_id_built_tidal_url_and_a_null_cover(): void
    {
        $this->subscriber(['link' => null, 'cover_url' => null, 'provider_id' => '298110043']);

        $this->callFeed()->assertOk()
            ->assertJsonPath('data.0.releases.0.cover_url', null)
            ->assertJsonPath('data.0.releases.0.tidal_url', 'https://tidal.com/browse/album/298110043');
    }

    #[Test]
    public function an_undated_release_reports_null_dates(): void
    {
        $this->subscriber(['released_on' => null]);

        $this->callFeed()->assertOk()
            ->assertJsonPath('data.0.releases.0.released_on', null)
            ->assertJsonPath('data.0.releases.0.year', null);
    }

    #[Test]
    public function it_flags_a_release_as_new_relative_to_last_viewed_at(): void
    {
        $user = $this->subscriber();

        // Never opened the Feed screen, so a just-arrived release is new.
        $this->callFeed()->assertOk()
            ->assertJsonPath('data.0.releases.0.is_new', true)
            ->assertJsonPath('meta.new_releases', 1);

        DB::table('artist_follows')
            ->where('user_id', $user->getKey())
            ->update(['last_viewed_at' => now()->addMinute()]);

        $this->callFeed()->assertOk()
            ->assertJsonPath('data.0.releases.0.is_new', false)
            ->assertJsonPath('meta.new_releases', 0);
    }

    #[Test]
    public function a_release_outside_the_new_window_is_never_new(): void
    {
        $user = User::factory()->withApiToken(self::TOKEN)->create();
        $artist = Artist::factory()->create();

        ArtistRelease::factory()->old()->create(['artist_id' => $artist->getKey()]);

        $user->followedArtists()->attach($artist);

        $this->callFeed()->assertOk()->assertJsonPath('data.0.releases.0.is_new', false);
    }

    #[Test]
    public function it_limits_releases_per_artist(): void
    {
        config(['minizo.feed.releases_per_artist' => 3]);

        $user = User::factory()->withApiToken(self::TOKEN)->create();
        $artist = Artist::factory()->create();

        ArtistRelease::factory()->count(10)->create(['artist_id' => $artist->getKey()]);

        $user->followedArtists()->attach($artist);

        $this->callFeed()->assertOk()
            ->assertJsonCount(3, 'data.0.releases')
            ->assertJsonPath('meta.releases_per_artist', 3);
    }

    #[Test]
    public function it_returns_an_empty_data_array_for_a_user_following_nobody(): void
    {
        User::factory()->withApiToken(self::TOKEN)->create();

        $this->callFeed()->assertOk()
            ->assertExactJsonStructure(['data', 'meta'])
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.artists', 0)
            ->assertJsonPath('meta.releases', 0)
            ->assertJsonPath('meta.new_releases', 0);
    }

    #[Test]
    public function it_does_not_leak_the_token_hash_or_password(): void
    {
        $this->subscriber();

        $body = $this->callFeed()->assertOk()->getContent();

        $this->assertStringNotContainsString(ApiToken::hash(self::TOKEN), $body);
        $this->assertStringNotContainsString('api_token', $body);
        $this->assertStringNotContainsString('password', $body);
    }

    // ---- stored data only

    #[Test]
    public function it_does_not_mark_the_feed_as_viewed(): void
    {
        $user = $this->subscriber();

        $before = DB::table('artist_follows')->where('user_id', $user->getKey())->value('last_viewed_at');

        $this->callFeed()->assertOk();

        $after = DB::table('artist_follows')->where('user_id', $user->getKey())->value('last_viewed_at');

        $this->assertSame($before, $after);
    }

    #[Test]
    public function it_makes_no_http_call(): void
    {
        $this->subscriber();

        Http::fake();

        $this->callFeed()->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_works_without_tidal_credentials(): void
    {
        $this->subscriber();

        config(['services.tidal.client_id' => null, 'services.tidal.client_secret' => null]);

        $this->callFeed()->assertOk()->assertJsonCount(1, 'data');
    }

    // ---- revocation

    #[Test]
    public function a_revoked_token_stops_working(): void
    {
        $user = $this->subscriber();

        $this->callFeed()->assertOk();

        ApiToken::revokeFor($user);

        $this->callFeed()->assertUnauthorized();
    }

    #[Test]
    public function regenerating_invalidates_the_previous_token(): void
    {
        $user = $this->subscriber();

        $fresh = ApiToken::issueFor($user);

        $this->callFeed()->assertUnauthorized();
        $this->callFeed($fresh)->assertOk();
    }
}
