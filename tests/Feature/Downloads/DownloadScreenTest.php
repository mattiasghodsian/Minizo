<?php

namespace Tests\Feature\Downloads;

use App\Enums\DownloadStatus;
use App\Enums\Permission;
use App\Jobs\DownloadTrackJob;
use App\Models\DownloadJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The Download screen: the form, the live queue and Recent activity. */
class DownloadScreenTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://music.youtube.com/watch?v=abc12345678';

    protected function setUp(): void
    {
        parent::setUp();

        $disk = Storage::fake('music');
        $disk->makeDirectory('Spanish');
        $disk->makeDirectory('Folk');

        Queue::fake();
    }

    private function job(User $user, array $attributes = []): DownloadJob
    {
        return DownloadJob::factory()->create([
            'user_id' => $user->id,
            'folder' => 'Spanish',
            ...$attributes,
        ]);
    }

    // ------------------------------------------------------------------- the form

    public function test_the_screen_is_the_post_login_landing_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('download'))
            ->assertOk()
            ->assertSee('New download', escape: false);
    }

    public function test_a_destination_is_preselected_so_queueing_is_one_click(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::download')
            ->assertSet('folder', 'Folk');
    }

    public function test_submitting_the_form_queues_a_download(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::download')
            ->set('url', self::URL)
            ->set('folder', 'Spanish')
            ->call('queueDownload')
            ->assertHasNoErrors()
            // The field clears, so a second paste does not silently re-queue the first.
            ->assertSet('url', '');

        $this->assertDatabaseHas('download_jobs', [
            'url' => self::URL,
            'folder' => 'Spanish',
            'status' => DownloadStatus::Queued->value,
        ]);

        Queue::assertPushed(DownloadTrackJob::class);
    }

    public function test_a_bad_url_is_reported_on_the_field(): void
    {
        // A user-fixable outcome belongs on the input, not in a toast that disappears.
        Livewire::actingAs(User::factory()->create())
            ->test('pages::download')
            ->set('url', 'not a url')
            ->call('queueDownload')
            ->assertHasErrors('url');

        $this->assertSame(0, DownloadJob::count());
    }

    public function test_a_user_without_the_downloader_permission_sees_no_form(): void
    {
        Livewire::actingAs(User::factory()->without([Permission::Downloader])->create())
            ->test('pages::download')
            ->assertSet('canDownload', false)
            ->assertDontSee('https://music.youtube.com/watch?v=xxxxxxxxxxx')
            ->assertSee('Use downloader', escape: false);
    }

    public function test_a_user_without_the_downloader_permission_cannot_queue_anyway(): void
    {
        // The form is hidden, which is not the same as the action being unreachable.
        Livewire::actingAs(User::factory()->without([Permission::Downloader])->create())
            ->test('pages::download')
            ->set('url', self::URL)
            ->call('queueDownload')
            ->assertForbidden();
    }

    public function test_a_locked_user_is_offered_only_their_locked_folder(): void
    {
        Storage::disk('music')->makeDirectory('Unprocessed');

        Livewire::actingAs(User::factory()->lockedDownloader('Unprocessed')->create())
            ->test('pages::download')
            ->assertSet('folderLocked', true)
            ->assertSet('destinations', ['Unprocessed']);
    }

    public function test_a_user_with_no_folder_access_is_told_why_they_cannot_download(): void
    {
        // Rather than being shown a Download button that fails when pressed.
        Livewire::actingAs(User::factory()->withoutFolders()->create())
            ->test('pages::download')
            ->assertSet('destinations', [])
            ->assertSee('Ask an administrator for folder access', escape: false);
    }

    // ------------------------------------------------------------------ the queue

    public function test_the_queue_shows_in_flight_and_recently_finished_rows(): void
    {
        // The design's mock queue holds a green "Completed" row beside two live ones:
        // a download that vanished the instant it succeeded would read as a failure.
        $user = User::factory()->create();

        $running = $this->job($user, ['status' => DownloadStatus::Running, 'progress_percent' => 40]);
        $justDone = $this->job($user, ['status' => DownloadStatus::Completed, 'finished_at' => now()]);
        $longDone = $this->job($user, [
            'status' => DownloadStatus::Completed,
            'finished_at' => now()->subSeconds((int) config('minizo.downloads.queue_linger') + 60),
        ]);

        $ids = Livewire::actingAs($user)
            ->test('pages::download')
            ->instance()
            ->queue
            ->pluck('id')
            ->all();

        $this->assertContains($running->id, $ids);
        $this->assertContains($justDone->id, $ids);
        $this->assertNotContains($longDone->id, $ids);
    }

    public function test_the_queue_only_shows_this_users_downloads(): void
    {
        $user = User::factory()->create();
        $other = $this->job(User::factory()->create(), ['status' => DownloadStatus::Running]);

        $ids = Livewire::actingAs($user)->test('pages::download')->instance()->queue->pluck('id')->all();

        $this->assertNotContains($other->id, $ids);
    }

    public function test_polling_stops_once_nothing_is_moving(): void
    {
        /*
         * An unconditional wire:poll means a request every three seconds from every
         * open tab, forever, to re-render a page that has not changed.
         */
        $user = User::factory()->create();

        $idle = Livewire::actingAs($user)->test('pages::download');
        $idle->assertSet('hasActiveJobs', false);
        $idle->assertDontSee('wire:poll', escape: false);

        $this->job($user, ['status' => DownloadStatus::Running]);

        $busy = Livewire::actingAs($user)->test('pages::download');
        $busy->assertSet('hasActiveJobs', true);
        $busy->assertSee('wire:poll', escape: false);
    }

    public function test_dismissing_a_queued_row_cancels_it_outright(): void
    {
        // Nothing is running, so there is no worker to ask - it can be settled here,
        // and the worker checks the status when it eventually starts.
        $user = User::factory()->create();
        $job = $this->job($user, ['status' => DownloadStatus::Queued]);

        Livewire::actingAs($user)->test('pages::download')->call('dismiss', $job->id);

        $this->assertSame(DownloadStatus::Cancelled, $job->refresh()->status);
    }

    public function test_dismissing_a_running_row_only_requests_a_cancel(): void
    {
        /*
         * The row must stay Running: the worker holds the yt-dlp child in another
         * process and is the only thing that can stop it. Marking it cancelled here
         * would tell the user it had stopped while the download carried on.
         */
        $user = User::factory()->create();
        $job = $this->job($user, ['status' => DownloadStatus::Running]);

        Livewire::actingAs($user)->test('pages::download')->call('dismiss', $job->id);

        $job->refresh();

        $this->assertSame(DownloadStatus::Running, $job->status);
        $this->assertNotNull($job->cancel_requested_at);
    }

    public function test_dismissing_a_finished_row_hides_it_without_losing_the_history(): void
    {
        // Recent activity is built from the same rows, so destroying them would erase
        // the history the design promises.
        $user = User::factory()->create();
        $job = $this->job($user, ['status' => DownloadStatus::Completed, 'finished_at' => now()]);

        Livewire::actingAs($user)->test('pages::download')->call('dismiss', $job->id);

        $job->refresh();

        $this->assertNotNull($job->hidden_at);
        $this->assertDatabaseHas('download_jobs', ['id' => $job->id]);
    }

    public function test_a_user_cannot_dismiss_someone_elses_download(): void
    {
        $job = $this->job(User::factory()->create(), ['status' => DownloadStatus::Running]);

        Livewire::actingAs(User::factory()->create())
            ->test('pages::download')
            ->call('dismiss', $job->id)
            ->assertForbidden();

        $this->assertNull($job->refresh()->cancel_requested_at);
    }

    public function test_dismissing_a_row_that_has_gone_is_a_404_not_a_crash(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::download')
            ->call('dismiss', 99999)
            ->assertNotFound();
    }

    // -------------------------------------------------------- recent activity

    public function test_recent_activity_lists_completed_downloads_newest_first(): void
    {
        $user = User::factory()->create();

        $older = $this->job($user, ['status' => DownloadStatus::Completed, 'finished_at' => now()->subDay()]);
        $newer = $this->job($user, ['status' => DownloadStatus::Completed, 'finished_at' => now()]);
        $failed = $this->job($user, ['status' => DownloadStatus::Failed, 'finished_at' => now()]);

        $ids = Livewire::actingAs($user)->test('pages::download')->instance()->recent->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ids);
        $this->assertNotContains($failed->id, $ids);
    }

    public function test_recent_activity_hides_folders_the_user_can_no_longer_see(): void
    {
        // Two filters, both required: user_id is whose downloads these were, folder access
        // is what they may know about now. Revoking a folder revokes its history.
        $user = User::factory()->withFolders(['Spanish'])->create();

        $visible = $this->job($user, ['status' => DownloadStatus::Completed, 'finished_at' => now()]);
        $hidden = $this->job($user, [
            'folder' => 'Folk',
            'status' => DownloadStatus::Completed,
            'finished_at' => now(),
        ]);

        $ids = Livewire::actingAs($user)->test('pages::download')->instance()->recent->pluck('id')->all();

        $this->assertSame([$visible->id], $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_recent_activity_fills_the_page_for_a_restricted_user(): void
    {
        // The folder filter has to narrow the query, not the result. Applied after the
        // limit, a user whose newest downloads are all in folders they cannot see got
        // an empty table while visible rows sat just past the cut.
        $user = User::factory()->withFolders(['Spanish'])->create();

        $limit = (int) config('minizo.downloads.recent_limit', 25);

        // Newest rows first, and all of them unreachable.
        for ($i = 0; $i < $limit; $i++) {
            $this->job($user, [
                'folder' => 'Folk',
                'status' => DownloadStatus::Completed,
                'finished_at' => now()->subMinutes($i),
            ]);
        }

        $visible = $this->job($user, [
            'folder' => 'Spanish',
            'status' => DownloadStatus::Completed,
            'finished_at' => now()->subDay(),
        ]);

        $ids = Livewire::actingAs($user)->test('pages::download')->instance()->recent->pluck('id')->all();

        $this->assertSame([$visible->id], $ids);
    }

    public function test_an_admin_can_preview_another_users_activity(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create(['name' => 'Bea']);

        $theirs = $this->job($other, ['status' => DownloadStatus::Completed, 'finished_at' => now()]);

        $ids = Livewire::actingAs($admin)
            ->test('pages::download')
            ->call('preview', $other->id)
            ->instance()
            ->recent
            ->pluck('id')
            ->all();

        $this->assertSame([$theirs->id], $ids);
    }

    public function test_the_preview_respects_the_previewed_users_folder_access_not_the_admins(): void
    {
        // The pills exist to answer "what does this user see", so applying the admin's
        // own access would make the feature lie.
        $admin = User::factory()->admin()->create();
        $restricted = User::factory()->withFolders(['Spanish'])->create();

        $this->job($restricted, [
            'folder' => 'Folk',
            'status' => DownloadStatus::Completed,
            'finished_at' => now(),
        ]);

        $recent = Livewire::actingAs($admin)
            ->test('pages::download')
            ->call('preview', $restricted->id)
            ->instance()
            ->recent;

        $this->assertCount(0, $recent);
    }

    public function test_a_non_admin_gets_no_preview_pills_and_cannot_preview(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $component = Livewire::actingAs($user)->test('pages::download');

        $this->assertCount(0, $component->instance()->previewUsers);

        $component->call('preview', $other->id)->assertForbidden();
    }

    public function test_a_preview_id_that_was_never_offered_falls_back_to_the_viewer(): void
    {
        // previewUserId crosses the wire, so it is re-resolved against the
        // authoritative list rather than trusted.
        $admin = User::factory()->admin()->create();

        $component = Livewire::actingAs($admin)->test('pages::download');
        $component->set('previewUserId', 99999);

        $this->assertTrue($component->instance()->previewUser->is($admin));
    }
}
