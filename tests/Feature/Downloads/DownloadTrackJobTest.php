<?php

namespace Tests\Feature\Downloads;

use App\Enums\DownloadStatus;
use App\Enums\Permission;
use App\Exceptions\DownloadCancelled;
use App\Exceptions\DownloadException;
use App\Jobs\DownloadTrackJob;
use App\Models\DownloadJob;
use App\Models\User;
use App\Services\Download\AudioDownloader;
use App\Services\Library\FileService;
use App\Support\LibraryFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAudioDownloader;
use Tests\TestCase;

/** The worker, against a downloader that touches no network. */
class DownloadTrackJobTest extends TestCase
{
    use RefreshDatabase;

    private FakeAudioDownloader $downloader;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('music')->makeDirectory('Spanish');

        $this->downloader = new FakeAudioDownloader;
        $this->app->instance(AudioDownloader::class, $this->downloader);
    }

    private function record(array $attributes = []): DownloadJob
    {
        return DownloadJob::factory()->create([
            'user_id' => User::factory()->create()->id,
            'folder' => 'Spanish',
            ...$attributes,
        ]);
    }

    private function work(DownloadJob $record): void
    {
        (new DownloadTrackJob($record))->handle($this->downloader, app(FileService::class));
    }

    public function test_a_successful_download_records_what_landed(): void
    {
        $record = $this->record();

        $this->work($record);

        $record->refresh();

        $this->assertSame(DownloadStatus::Completed, $record->status);
        $this->assertSame('Bad Bunny - Monaco.flac', $record->filename);
        $this->assertSame('Monaco', $record->title);
        $this->assertSame('Bad Bunny', $record->artist);
        $this->assertSame(100, $record->progress_percent);
        $this->assertNotNull($record->started_at);
        $this->assertNotNull($record->finished_at);
    }

    public function test_the_folder_the_file_landed_in_is_no_longer_served_from_cache(): void
    {
        // The download writes from inside a worker, bypassing FileService, so without an
        // explicit invalidation the Files screen serves the pre-download listing for up to
        // cache_ttl.
        $this->downloader->writeFile = true;

        $files = app(FileService::class);
        $folder = new LibraryFolder('Spanish');

        $this->actingAs(User::factory()->admin()->create());

        // Warm the cache on the empty folder.
        $this->assertCount(0, $files->all($folder));

        $this->work($this->record());

        $this->assertCount(1, $files->all($folder));
    }

    public function test_progress_reaches_the_row_while_the_download_runs(): void
    {
        $this->downloader->emit = [10, 55, 100];

        $record = $this->record();

        $this->work($record);

        // The percentage ends at 100 via markCompleted, but the intermediate writes
        // are what the bar animates on; the size label proves one of them landed.
        $this->assertSame('38.40MiB', $record->refresh()->size_label);
    }

    public function test_a_remote_failure_is_recorded_on_the_row_and_not_retried(): void
    {
        // "Video unavailable" will say the same thing on a second attempt, so burning
        // retries on it only delays the message the user needs to see.
        $this->downloader->throw = DownloadException::remote('Video unavailable');

        $record = $this->record();

        $this->work($record);

        $record->refresh();

        $this->assertSame(DownloadStatus::Failed, $record->status);
        $this->assertSame('Video unavailable', $record->error);
        $this->assertNotNull($record->finished_at);
    }

    public function test_a_missing_yt_dlp_fails_the_row_with_an_actionable_message(): void
    {
        // Rather than an exception in a worker log that nobody reads. This is the
        // failure a fresh install hits first.
        $this->downloader->configured = false;

        $record = $this->record();

        $this->work($record);

        $this->assertSame(DownloadStatus::Failed, $record->refresh()->status);
        $this->assertStringContainsString('yt-dlp', (string) $record->error);
    }

    public function test_a_cancelled_download_is_not_recorded_as_a_failure(): void
    {
        // From the user's point of view cancelling worked. Marking it failed would
        // put a red row in the queue for something they asked for.
        $this->downloader->throw = DownloadCancelled::make();

        $record = $this->record();

        $this->work($record);

        $record->refresh();

        $this->assertSame(DownloadStatus::Cancelled, $record->status);
        $this->assertNull($record->error);
    }

    public function test_a_job_cancelled_before_a_worker_picked_it_up_never_downloads(): void
    {
        $record = $this->record(['cancel_requested_at' => now()]);

        $this->work($record);

        $this->assertSame(DownloadStatus::Cancelled, $record->refresh()->status);
        $this->assertSame([], $this->downloader->calls);
    }

    public function test_permission_revoked_between_enqueue_and_run_stops_the_download(): void
    {
        /*
         * The check that matters is the one taken before the network call, not the one
         * taken when the button was clicked. A job can sit in the queue for minutes
         * while an admin revokes access.
         */
        $user = User::factory()->create();
        $record = $this->record(['user_id' => $user->id]);

        $user->forceFill([Permission::Downloader->column() => false])->save();

        $this->work($record);

        $record->refresh();

        $this->assertSame(DownloadStatus::Failed, $record->status);
        $this->assertSame([], $this->downloader->calls);
    }

    public function test_folder_access_revoked_between_enqueue_and_run_stops_the_download(): void
    {
        $user = User::factory()->withFolders(['Spanish'])->create();
        $record = $this->record(['user_id' => $user->id]);

        $user->forceFill(['folder_access' => ['Folk']])->save();

        $this->work($record);

        $this->assertSame(DownloadStatus::Failed, $record->refresh()->status);
        $this->assertSame([], $this->downloader->calls);
    }

    public function test_an_unexpected_death_still_marks_the_row_failed(): void
    {
        /*
         * failed() covers what handle() never sees: an out-of-memory kill, a worker
         * restart, a genuine bug. Without it the row sits at "Downloading" forever and
         * only the stall reaper eventually notices - fifteen minutes later.
         */
        $record = $this->record(['status' => DownloadStatus::Running, 'started_at' => now()]);

        (new DownloadTrackJob($record))->failed(new \RuntimeException('worker died'));

        $record->refresh();

        $this->assertSame(DownloadStatus::Failed, $record->status);
        $this->assertSame('worker died', $record->error);
    }

    public function test_failed_does_not_overwrite_an_already_terminal_row(): void
    {
        // A cancelled job whose retry then fails must stay cancelled, or the queue
        // would show a red row for something the user chose to stop.
        $record = $this->record(['status' => DownloadStatus::Cancelled, 'finished_at' => now()]);

        (new DownloadTrackJob($record))->failed(new \RuntimeException('late failure'));

        $this->assertSame(DownloadStatus::Cancelled, $record->refresh()->status);
    }
}
