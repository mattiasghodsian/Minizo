<?php

namespace Tests\Feature\Downloads;

use App\Enums\DownloadStatus;
use App\Models\DownloadJob;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The stall reaper - the download pipeline's only timeout. */
class ReapDownloadsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function job(array $attributes = []): DownloadJob
    {
        return DownloadJob::factory()->create([
            'user_id' => User::factory()->create()->id,
            ...$attributes,
        ]);
    }

    public function test_it_fails_a_running_download_whose_progress_went_stale(): void
    {
        $stalled = DownloadJob::factory()
            ->stalled()
            ->create(['user_id' => User::factory()->create()->id]);

        $this->artisan('minizo:downloads:reap')->assertSuccessful();

        $stalled->refresh();

        $this->assertSame(DownloadStatus::Failed, $stalled->status);
        $this->assertStringContainsString('stalled', (string) $stalled->error);
        $this->assertNotNull($stalled->finished_at);
    }

    public function test_it_leaves_a_slow_but_reporting_download_alone(): void
    {
        /*
         * Detection is by staleness, not wall-clock age - precisely so a legitimate
         * 40-minute download survives. Killing those would be far worse than the
         * problem being solved.
         */
        $slow = $this->job([
            'status' => DownloadStatus::Running,
            'started_at' => now()->subHours(3),
            'progress_updated_at' => now()->subSeconds(5),
        ]);

        $this->artisan('minizo:downloads:reap')->assertSuccessful();

        $this->assertSame(DownloadStatus::Running, $slow->refresh()->status);
    }

    public function test_it_never_touches_a_queued_download(): void
    {
        /*
         * A queued row has no progress to go stale - it is waiting for a worker, which
         * is not a fault. Reaping these would break every install whose queue is simply
         * backed up, which is the normal state of a busy one.
         */
        $queued = $this->job(['status' => DownloadStatus::Queued, 'created_at' => now()->subDay()]);

        $this->artisan('minizo:downloads:reap')->assertSuccessful();

        $this->assertSame(DownloadStatus::Queued, $queued->refresh()->status);
    }

    public function test_it_leaves_terminal_rows_alone(): void
    {
        $completed = $this->job([
            'status' => DownloadStatus::Completed,
            'finished_at' => now()->subDays(2),
            'progress_updated_at' => now()->subDays(2),
        ]);

        $this->artisan('minizo:downloads:reap')->assertSuccessful();

        $this->assertSame(DownloadStatus::Completed, $completed->refresh()->status);
    }

    public function test_it_prunes_history_past_the_retention_window(): void
    {
        $old = $this->job([
            'status' => DownloadStatus::Completed,
            'finished_at' => now()->subDays((int) config('minizo.downloads.history_days') + 1),
        ]);

        $recent = $this->job([
            'status' => DownloadStatus::Completed,
            'finished_at' => now()->subDay(),
        ]);

        $this->artisan('minizo:downloads:reap')->assertSuccessful();

        $this->assertDatabaseMissing('download_jobs', ['id' => $old->id]);
        $this->assertDatabaseHas('download_jobs', ['id' => $recent->id]);
    }

    public function test_it_reports_what_it_did(): void
    {
        // Prunable deletes silently, so the count is logged to keep the retention
        // promise auditable after the fact.
        DownloadJob::factory()
            ->stalled()
            ->create(['user_id' => User::factory()->create()->id]);

        $this->artisan('minizo:downloads:reap')
            ->expectsOutputToContain('1 stalled download(s) marked failed.')
            ->assertSuccessful();
    }

    public function test_it_is_scheduled(): void
    {
        // The reaper only works if it actually runs; a command nobody invokes is a
        // timeout that never fires.
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'minizo:downloads:reap'));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
    }
}
