<?php

namespace Tests\Feature\Sharing;

use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** The public surface. */
class PublicSharePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $disk = Storage::fake('music');
        $disk->makeDirectory('Spanish');
        $disk->makeDirectory('Private');
        $disk->put('Spanish/song.flac', 'audio-one');
        $disk->put('Spanish/other.flac', 'audio-two');
        $disk->put('Private/secret.flac', 'not shared');
    }

    private function folderShare(): Share
    {
        return Share::factory()->folder('Spanish')->create();
    }

    private function trackShare(): Share
    {
        return Share::factory()->track('Spanish', 'song.flac')->create();
    }

    // --------------------------------------------------------------------- page

    public function test_a_stranger_can_open_a_folder_share_with_no_account(): void
    {
        $share = $this->folderShare();

        $this->get(route('share.show', $share->token))
            ->assertOk()
            ->assertSee('SHARED FOLDER', escape: false)
            ->assertSee('Spanish')
            ->assertSee('song')
            ->assertSee('other')
            ->assertSee('Download all (.zip)', escape: false);

        // Still nobody logged in - the page did not create a session identity.
        $this->assertGuest();
    }

    public function test_a_track_share_shows_one_track_and_no_listing(): void
    {
        $share = $this->trackShare();

        $this->get(route('share.show', $share->token))
            ->assertOk()
            ->assertSee('SHARED TRACK', escape: false)
            ->assertSee('Download track', escape: false)
            // The per-track download rows belong to a folder share only.
            ->assertDontSee('other');
    }

    public function test_the_page_renders_no_app_chrome(): void
    {
        /*
         * The reason this has its own layout. layouts::app reads auth()->user() throughout
         * - the sidebar folder list, the user row, every permission check - and a stranger
         * has none of it.
         */
        $this->get(route('share.show', $this->folderShare()->token))
            ->assertOk()
            ->assertDontSee('LIBRARY', escape: false)
            ->assertDontSee(route('download'))
            ->assertDontSee('Log out', escape: false);
    }

    public function test_the_page_is_identical_whether_or_not_the_visitor_is_logged_in(): void
    {
        /*
         * Otherwise "open it in a private window" - the only check anyone actually
         * performs on a share link - would prove nothing.
         */
        $share = $this->folderShare();

        $anonymous = $this->get(route('share.show', $share->token))->getContent();

        $signedIn = $this->actingAs(User::factory()->create())
            ->get(route('share.show', $share->token))
            ->getContent();

        $this->assertSame($anonymous, $signedIn);
    }

    public function test_the_page_asks_not_to_be_indexed_and_not_to_leak_the_token(): void
    {
        // A revocable link that outlives itself in a search result is worse than useless,
        // and the token must not travel in a Referer header.
        $this->get(route('share.show', $this->folderShare()->token))
            ->assertSee('name="robots"', escape: false)
            ->assertSee('noindex', escape: false)
            ->assertSee('name="referrer"', escape: false)
            ->assertSee('no-referrer', escape: false);
    }

    public function test_opening_a_link_records_the_access(): void
    {
        // "Was this link ever used?" is the first question anyone asks about a leak.
        $share = $this->folderShare();

        $this->get(route('share.show', $share->token))->assertOk();

        $share->refresh();

        $this->assertSame(1, $share->access_count);
        $this->assertNotNull($share->last_accessed_at);
    }

    // ------------------------------------------------------------------- refusal

    public function test_an_expired_a_revoked_and_an_unknown_token_render_the_same_page(): void
    {
        /*
         * Byte-identical. Distinguishing them tells someone probing for
         * tokens which ones were once valid, which is the only thing they could learn from
         * guessing.
         */
        $expired = Share::factory()->folder('Spanish')->expired()->create();
        $revoked = Share::factory()->folder('Spanish')->revoked()->create();

        $bodies = [];

        foreach ([$expired->token, $revoked->token, 'neverexisted1'] as $token) {
            $response = $this->get(route('share.show', $token));

            $response->assertNotFound()->assertSee('This link no longer works', escape: false);

            $bodies[] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies));
    }

    public function test_a_dead_link_reveals_nothing_about_what_it_pointed_at(): void
    {
        $revoked = Share::factory()->folder('Spanish')->revoked()->create();

        $this->get(route('share.show', $revoked->token))
            ->assertDontSee('Spanish')
            ->assertDontSee('song.flac');
    }

    public function test_a_link_stops_working_the_moment_it_expires(): void
    {
        $share = Share::factory()->folder('Spanish')->create(['expires_at' => now()->addMinute()]);

        $this->get(route('share.show', $share->token))->assertOk();

        $this->travel(2)->minutes();

        $this->get(route('share.show', $share->token))->assertNotFound();
    }

    public function test_the_public_routes_are_throttled(): void
    {
        // Not because the token is guessable - it is 71 bits - but because these routes
        // stream files, and an unthrottled public endpoint that reads gigabytes off disk
        // can exhaust a self-hosted box whether or not the caller ever finds a valid link.
        config()->set('minizo.shares.rate_limit', 3);

        $token = $this->folderShare()->token;

        foreach (range(1, 3) as $ignored) {
            $this->get(route('share.show', $token))->assertOk();
        }

        $this->get(route('share.show', $token))->assertStatus(429);
    }

    // ------------------------------------------------------------------ download

    public function test_a_track_share_downloads_its_file(): void
    {
        $share = $this->trackShare();

        $response = $this->get(route('share.download', $share->token));

        $response->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=song.flac');

        $this->assertSame('audio-one', $response->streamedContent());
    }

    public function test_a_folder_share_downloads_a_zip_of_every_track(): void
    {
        $share = $this->folderShare();

        $response = $this->get(route('share.download', $share->token));

        $response->assertOk()->assertHeader('content-type', 'application/zip');

        // Written out and reopened, because "it returned bytes" is not the same as "it
        // returned a valid archive" - and STORE-mode streaming is exactly where that
        // could go wrong.
        $path = tempnam(sys_get_temp_dir(), 'minizo-zip-');
        file_put_contents($path, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the response was not a readable zip');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);

        $this->assertSame(['other.flac', 'song.flac'], $names);
        $this->assertSame('audio-one', $zip->getFromName('song.flac'));

        $zip->close();
        @unlink($path);
    }

    public function test_the_zip_carries_no_content_length_because_it_is_streamed(): void
    {
        /*
         * The visible cost of streaming: the compressed size is not known until the last
         * byte, so browsers show an indeterminate progress bar. Asserted so the trade is
         * a decision rather than a surprise.
         */
        $response = $this->get(route('share.download', $this->folderShare()->token));

        $this->assertNull($response->headers->get('Content-Length'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    public function test_a_single_track_can_be_downloaded_out_of_a_folder_share(): void
    {
        $share = $this->folderShare();

        $response = $this->get(route('share.download.track', [$share->token, 'other.flac']));

        $response->assertOk();
        $this->assertSame('audio-two', $response->streamedContent());
    }

    public function test_a_filename_outside_the_share_is_refused(): void
    {
        /*
         * The filename is matched against the share's OWN listing, never joined onto a
         * path - so a file in another folder is simply not in the list.
         */
        $share = $this->folderShare();

        $this->get(route('share.download.track', [$share->token, 'secret.flac']))->assertNotFound();
    }

    public function test_a_traversal_attempt_in_the_filename_is_refused(): void
    {
        $share = $this->folderShare();

        // The route regex rejects separators outright; this covers what gets past it.
        $this->get('/s/'.$share->token.'/download/'.urlencode('..%2F..%2F.env'))->assertNotFound();
        $this->get('/s/'.$share->token.'/download/'.urlencode('...env'))->assertNotFound();
    }

    public function test_a_track_share_cannot_be_used_to_reach_its_folder(): void
    {
        // The narrowest case: a track share names a folder, and that must not become
        // permission to read the rest of it.
        $share = $this->trackShare();

        $this->get(route('share.download.track', [$share->token, 'other.flac']))->assertNotFound();
    }

    public function test_downloads_from_a_dead_link_are_refused(): void
    {
        $revoked = Share::factory()->track('Spanish', 'song.flac')->revoked()->create();

        $this->get(route('share.download', $revoked->token))->assertNotFound();
        $this->get(route('share.download.track', [$revoked->token, 'song.flac']))->assertNotFound();
    }

    public function test_a_download_of_a_file_that_has_gone_is_a_dead_page_not_a_crash(): void
    {
        $share = $this->trackShare();

        Storage::disk('music')->delete('Spanish/song.flac');

        $this->get(route('share.download', $share->token))->assertNotFound();
    }

    public function test_nothing_behind_a_link_is_cacheable(): void
    {
        // A revocable URL must not sit in a shared cache after it has been revoked.
        $this->get(route('share.download', $this->trackShare()->token))
            ->assertHeader('cache-control', 'no-store, private');
    }
}
