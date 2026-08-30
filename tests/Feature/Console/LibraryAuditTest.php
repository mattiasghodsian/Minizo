<?php

namespace Tests\Feature\Console;

use App\Models\DownloadJob;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The audit for references to folders that no longer exist. */
class LibraryAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $disk = Storage::fake('music');
        $disk->makeDirectory('Spanish');
        $disk->makeDirectory('Folk');
    }

    #[Test]
    public function a_clean_library_reports_nothing_and_succeeds(): void
    {
        Share::factory()->create(['folder' => 'Spanish', 'revoked_at' => null]);
        User::factory()->withFolders(['Spanish', 'Folk'])->create();
        DownloadJob::factory()->create(['folder' => 'Folk']);

        $this->artisan('minizo:library:audit')
            ->expectsOutputToContain('Nothing dangling')
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------------- shares

    #[Test]
    public function it_reports_a_live_share_into_a_folder_that_has_gone(): void
    {
        Share::factory()->create([
            'folder' => 'Deleted On The Host',
            'name' => 'Old mixtape',
            'revoked_at' => null,
        ]);

        $this->artisan('minizo:library:audit')
            ->expectsOutputToContain('Old mixtape')
            ->expectsOutputToContain('Deleted On The Host')
            // Non-zero, so this is usable from cron: a command that always succeeds tells
            // nobody anything.
            ->assertExitCode(1);
    }

    #[Test]
    public function an_already_dead_share_is_not_reported(): void
    {
        // It is already revoked; saying so again is noise, and re-revoking would rewrite a
        // timestamp the 30-day retention clock runs from.
        Share::factory()->create(['folder' => 'Gone', 'revoked_at' => now()->subDay()]);

        $this->artisan('minizo:library:audit')
            ->expectsOutputToContain('Nothing dangling')
            ->assertExitCode(0);
    }

    #[Test]
    public function prune_revokes_a_dangling_share_rather_than_deleting_it(): void
    {
        $share = Share::factory()->create(['folder' => 'Gone', 'revoked_at' => null]);

        $this->artisan('minizo:library:audit --prune')->assertExitCode(0);

        /*
         * Revoked, not deleted - the same choice every other revocation makes, so the 30-day
         * audit trail survives and "what was shared out of the folder that disappeared" stays
         * answerable.
         */
        $this->assertModelExists($share);
        $this->assertNotNull($share->refresh()->revoked_at);
    }

    #[Test]
    public function prune_leaves_a_share_into_a_real_folder_alone(): void
    {
        $good = Share::factory()->create(['folder' => 'Spanish', 'revoked_at' => null]);

        $this->artisan('minizo:library:audit --prune');

        $this->assertNull($good->refresh()->revoked_at);
    }

    // ------------------------------------------------------------- folder access

    #[Test]
    public function it_reports_folder_access_that_points_nowhere(): void
    {
        $user = User::factory()->withFolders(['Spanish', 'Vanished'])->create(['email' => 'bea@example.com']);

        $this->artisan('minizo:library:audit')
            ->expectsOutputToContain('bea@example.com')
            ->expectsOutputToContain('Vanished')
            ->assertExitCode(1);

        $this->assertSame(['Spanish', 'Vanished'], $user->refresh()->folder_access);
    }

    #[Test]
    public function prune_trims_the_stale_entries_and_keeps_the_rest(): void
    {
        $user = User::factory()->withFolders(['Spanish', 'Vanished', 'Folk'])->create();

        $this->artisan('minizo:library:audit --prune')->assertExitCode(0);

        // Alphabetical rather than as-written: FolderAccess::of() sorts on the way in, and
        // the prune keeps whatever order was stored rather than reshuffling it.
        $this->assertSame(['Folk', 'Spanish'], array_values($user->refresh()->folder_access));
    }

    #[Test]
    public function a_user_with_access_to_everything_can_never_dangle(): void
    {
        // The `*` sentinel means "everything that exists", so it resolves against whatever is
        // on disk today and has nothing to go stale.
        $user = User::factory()->create(['folder_access' => ['*']]);

        $this->artisan('minizo:library:audit')
            ->expectsOutputToContain('Nothing dangling')
            ->assertExitCode(0);

        $this->assertSame(['*'], $user->refresh()->folder_access);
    }

    #[Test]
    public function pruning_every_entry_leaves_an_empty_list_not_null(): void
    {
        $user = User::factory()->withFolders(['Gone', 'AlsoGone'])->create();

        $this->artisan('minizo:library:audit --prune');

        // [] rather than null: both mean "no folders", but null reads as "never configured".
        $this->assertSame([], $user->refresh()->folder_access);
    }

    // ------------------------------------------------------------ download history

    #[Test]
    public function download_history_is_reported_but_never_pruned(): void
    {
        $job = DownloadJob::factory()->create(['folder' => 'Renamed On The Host']);

        $this->artisan('minizo:library:audit --prune')
            ->expectsOutputToContain('download history row(s)')
            ->expectsOutputToContain('Left alone');

        $this->assertModelExists($job);
        $this->assertSame('Renamed On The Host', $job->refresh()->folder);
    }

    #[Test]
    public function history_alone_still_reports_a_finding(): void
    {
        DownloadJob::factory()->create(['folder' => 'Gone']);

        // Worth an operator's attention even though nothing will be changed for it.
        $this->artisan('minizo:library:audit')->assertExitCode(1);
    }
}
