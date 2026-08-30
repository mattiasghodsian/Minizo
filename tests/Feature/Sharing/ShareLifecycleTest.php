<?php

namespace Tests\Feature\Sharing;

use App\Enums\Permission;
use App\Enums\ShareExpiry;
use App\Enums\ShareType;
use App\Exceptions\ShareException;
use App\Models\Share;
use App\Models\User;
use App\Services\Library\FileService;
use App\Services\Library\FolderService;
use App\Services\Sharing\ShareService;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use App\Support\Sharing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Creating, following and killing links. */
class ShareLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $disk = Storage::fake('music');
        $disk->makeDirectory('Spanish');
        $disk->makeDirectory('Folk');
        $disk->put('Spanish/song.flac', 'audio');
        $disk->put('Spanish/other.flac', 'audio');
        $disk->put('Folk/tune.flac', 'audio');

        Sharing::fake(true);
    }

    protected function tearDown(): void
    {
        Sharing::clearFake();

        parent::tearDown();
    }

    private function shares(): ShareService
    {
        return app(ShareService::class);
    }

    private function file(string $folder = 'Spanish', string $name = 'song.flac'): LibraryFile
    {
        return new LibraryFile(new LibraryFolder($folder), $name);
    }

    // ------------------------------------------------------------------ creation

    public function test_it_publishes_a_folder(): void
    {
        $user = User::factory()->create();

        $share = $this->shares()->shareFolder($user, new LibraryFolder('Spanish'), ShareExpiry::OneDay);

        $this->assertSame(ShareType::Folder, $share->type);
        $this->assertSame('Spanish', $share->folder);
        $this->assertNull($share->filename);
        $this->assertSame($user->id, $share->user_id);
        $this->assertTrue($share->isLive());
        $this->assertSame(12, strlen($share->token));
    }

    public function test_it_publishes_one_track_and_drops_the_extension_from_the_label(): void
    {
        // The design shows the track's name, not the file's - ".flac" is noise on a page
        // whose entire subject is one audio file.
        $share = $this->shares()->shareFile(
            User::factory()->create(),
            $this->file(),
            ShareExpiry::SixHours,
        );

        $this->assertSame(ShareType::Track, $share->type);
        $this->assertSame('Spanish', $share->folder);
        $this->assertSame('song.flac', $share->filename);
        $this->assertSame('song', $share->name);
    }

    public function test_an_empty_folder_cannot_be_shared(): void
    {
        /*
         * Refused rather than allowed. A folder share whose page lists nothing and whose
         * "Download all" produces an empty archive looks broken to whoever receives it,
         * and the person who shared it has no way to tell.
         */
        Storage::disk('music')->makeDirectory('Empty');

        $this->expectException(ShareException::class);

        $this->shares()->shareFolder(User::factory()->create(), new LibraryFolder('Empty'), ShareExpiry::OneDay);
    }

    public function test_a_user_without_the_share_permission_cannot_publish(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->shares()->shareFolder(
            User::factory()->without([Permission::Share])->create(),
            new LibraryFolder('Spanish'),
            ShareExpiry::OneDay,
        );
    }

    public function test_a_user_cannot_publish_a_folder_they_cannot_see(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->shares()->shareFolder(
            User::factory()->withFolders(['Folk'])->create(),
            new LibraryFolder('Spanish'),
            ShareExpiry::OneDay,
        );
    }

    public function test_nothing_can_be_published_while_the_instance_switch_is_off(): void
    {
        // Checked in the service as well as the policy: a request that started before an
        // admin flipped the switch must not slip past it.
        Sharing::fake(false);

        $this->expectException(AuthorizationException::class);

        $this->shares()->shareFolder(User::factory()->create(), new LibraryFolder('Spanish'), ShareExpiry::OneDay);
    }

    public function test_tokens_are_unique_and_unguessable_from_each_other(): void
    {
        $user = User::factory()->create();

        $tokens = collect(range(1, 20))->map(
            fn (): string => $this->shares()->shareFolder($user, new LibraryFolder('Spanish'), ShareExpiry::OneDay)->token
        );

        $this->assertCount(20, $tokens->unique());

        // Not derived from the row id, which would make every link guessable from one.
        foreach ($tokens as $token) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $token);
        }
    }

    // ---------------------------------------------------------------- resolution

    public function test_a_live_token_resolves(): void
    {
        $share = Share::factory()->folder('Spanish')->create();

        $this->assertTrue($this->shares()->resolve($share->token)?->is($share));
    }

    public function test_an_expired_a_revoked_and_an_unknown_token_are_indistinguishable(): void
    {
        /*
         * All three return null. Telling a stranger "that link existed but
         * expired" versus "that link never existed" leaks whether a token was ever valid,
         * which is the one useful thing someone probing for tokens could learn.
         */
        $expired = Share::factory()->folder('Spanish')->expired()->create();
        $revoked = Share::factory()->folder('Spanish')->revoked()->create();

        $this->assertNull($this->shares()->resolve($expired->token));
        $this->assertNull($this->shares()->resolve($revoked->token));
        $this->assertNull($this->shares()->resolve('neverexisted'));
    }

    public function test_a_folder_share_exposes_every_track_in_the_folder(): void
    {
        $share = Share::factory()->folder('Spanish')->create();

        $names = array_map(fn (LibraryFile $f): string => $f->filename, $this->shares()->contents($share));

        $this->assertSame(['other.flac', 'song.flac'], $names);
    }

    public function test_a_track_share_exposes_exactly_one_file(): void
    {
        // Not the folder it happens to live in - which is what a share storing only a
        // folder would have had to do.
        $share = Share::factory()->track('Spanish', 'song.flac')->create();

        $names = array_map(fn (LibraryFile $f): string => $f->filename, $this->shares()->contents($share));

        $this->assertSame(['song.flac'], $names);
    }

    public function test_a_hand_edited_filename_resolves_to_nothing(): void
    {
        /*
         * The row is the only authorization a public request has, so a tampered one must
         * fail closed. contents() re-resolves against the folder's real listing rather
         * than joining the stored name onto a path.
         */
        $share = Share::factory()->create();
        $share->forceFill(['type' => ShareType::Track, 'filename' => '../../.env'])->save();

        $this->assertSame([], $this->shares()->contents($share->fresh()));
    }

    public function test_contents_needs_no_authenticated_user(): void
    {
        // The whole point: a public request has nobody to check anything against.
        // FileService::all() would throw here, which is why allUnguarded() exists.
        $this->assertGuest();

        $share = Share::factory()->folder('Spanish')->create();

        $this->assertCount(2, $this->shares()->contents($share));
    }

    // -------------------------------------------------------------------- fan-out

    public function test_renaming_a_folder_keeps_its_links_working(): void
    {
        // The promise the Rename-folder modal makes in so many words: "Existing share
        // links keep working."
        $share = Share::factory()->folder('Spanish')->create();

        $this->actingAs(User::factory()->admin()->create());

        app(FolderService::class)->rename(new LibraryFolder('Spanish'), 'Espanol');

        $share->refresh();

        $this->assertSame('Espanol', $share->folder);
        $this->assertTrue($share->isLive());
        $this->assertCount(2, $this->shares()->contents($share));

        // The label is untouched: someone was told they were getting "Spanish", and
        // that is what the link should still say it is.
        $this->assertSame('Spanish', $share->name);
    }

    public function test_deleting_a_folder_revokes_its_links_immediately(): void
    {
        // Exactly what the Delete-folder modal warns: "any active share links pointing at
        // it stop working immediately".
        $share = Share::factory()->folder('Spanish')->create();

        $this->actingAs(User::factory()->admin()->create());

        app(FolderService::class)->delete(new LibraryFolder('Spanish'));

        $share->refresh();

        $this->assertNotNull($share->revoked_at);
        $this->assertNull($this->shares()->resolve($share->token));
    }

    public function test_a_deleted_folders_links_are_revoked_not_erased(): void
    {
        /*
         * The audit trail is the reason a deleted folder does not simply cascade its
         * shares away - "what was published out of the folder I just deleted" is a
         * question worth being able to answer for 30 days.
         */
        $share = Share::factory()->folder('Spanish')->create();

        $this->actingAs(User::factory()->admin()->create());

        app(FolderService::class)->delete(new LibraryFolder('Spanish'));

        $this->assertDatabaseHas('shares', ['id' => $share->id]);
    }

    public function test_revoking_for_a_folder_leaves_an_already_dead_links_timestamp_alone(): void
    {
        // Re-revoking would rewrite the audit record with the wrong moment.
        $dead = Share::factory()->folder('Spanish')->revoked()->create();
        $original = $dead->revoked_at;

        $this->shares()->revokeForFolder(new LibraryFolder('Spanish'));

        $this->assertEquals($original, $dead->fresh()->revoked_at);
    }

    public function test_moving_a_shared_track_keeps_its_link_working(): void
    {
        $share = Share::factory()->track('Spanish', 'song.flac')->create();

        $this->actingAs(User::factory()->admin()->create());

        app(FileService::class)->move($this->file(), new LibraryFolder('Folk'));

        $share->refresh();

        $this->assertSame('Folk', $share->folder);
        $this->assertCount(1, $this->shares()->contents($share));
    }

    public function test_renaming_a_shared_track_keeps_its_link_working(): void
    {
        $share = Share::factory()->track('Spanish', 'song.flac')->create();

        $this->actingAs(User::factory()->admin()->create());

        app(FileService::class)->rename($this->file(), 'Artist - Title.flac');

        $share->refresh();

        $this->assertSame('Artist - Title.flac', $share->filename);
        $this->assertCount(1, $this->shares()->contents($share));
    }

    public function test_deleting_a_shared_track_revokes_its_link(): void
    {
        // A link to a file that is gone must stop resolving, not 404 halfway through a
        // download.
        $share = Share::factory()->track('Spanish', 'song.flac')->create();

        $this->actingAs(User::factory()->admin()->create());

        app(FileService::class)->delete($this->file());

        $this->assertNotNull($share->fresh()->revoked_at);
    }

    public function test_a_folder_share_is_unaffected_by_deleting_one_track_from_it(): void
    {
        // Only the track share for that exact filename dies.
        $folderShare = Share::factory()->folder('Spanish')->create();

        $this->actingAs(User::factory()->admin()->create());

        app(FileService::class)->delete($this->file());

        $this->assertNull($folderShare->fresh()->revoked_at);
        $this->assertCount(1, $this->shares()->contents($folderShare->fresh()));
    }

    // ------------------------------------------------------------------- expiry

    public function test_a_link_dies_on_its_own_with_no_job_involved(): void
    {
        // Expiry is computed from expires_at on every read, never stored as a flag. A stored
        // boolean would need a scheduled job to flip it, and every minute between the expiry
        // instant and that job would be a link the UI called live and the server refused.
        $share = Share::factory()->create(['expires_at' => now()->addMinute()]);

        $this->assertTrue($share->isLive());

        $this->travel(2)->minutes();

        $this->assertTrue($share->fresh()->isDead());
        $this->assertNull($this->shares()->resolve($share->token));
    }

    public function test_revoking_is_idempotent(): void
    {
        $share = Share::factory()->create();

        $share->revoke();
        $first = $share->revoked_at;

        $this->travel(1)->minute();
        $share->revoke();

        $this->assertEquals($first, $share->fresh()->revoked_at);
    }

    public function test_dead_links_are_pruned_only_after_the_retention_window(): void
    {
        $recentlyDead = Share::factory()->expired()->create();
        $longDead = Share::factory()->stale()->create();

        $this->artisan('model:prune', ['--model' => [Share::class]])->assertSuccessful();

        $this->assertDatabaseHas('shares', ['id' => $recentlyDead->id]);
        $this->assertDatabaseMissing('shares', ['id' => $longDead->id]);
    }

    public function test_a_live_link_is_never_pruned(): void
    {
        $live = Share::factory()->create();

        $this->artisan('model:prune', ['--model' => [Share::class]])->assertSuccessful();

        $this->assertDatabaseHas('shares', ['id' => $live->id]);
    }

    public function test_pruning_is_scheduled(): void
    {
        // Retention that nobody enforces is not retention.
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'model:prune'));

        $this->assertCount(1, $events);
    }

    public function test_deleting_an_account_takes_its_links_with_it(): void
    {
        // A link with no owner cannot be audited, and the screen is organised entirely by
        // who created what.
        $user = User::factory()->create();
        $share = Share::factory()->create(['user_id' => $user->id]);

        $user->delete();

        $this->assertDatabaseMissing('shares', ['id' => $share->id]);
    }
}
