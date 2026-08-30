<?php

namespace Tests\Feature\Downloads;

use App\Exceptions\DownloadCancelled;
use App\Models\DownloadJob;
use App\Models\User;
use App\Services\Download\ProgressWriter;
use App\Support\DownloadProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The throttle, and the cancellation check that rides along with it. */
class ProgressWriterTest extends TestCase
{
    use RefreshDatabase;

    private function record(array $attributes = []): DownloadJob
    {
        return DownloadJob::factory()->create([
            'user_id' => User::factory()->create()->id,
            ...$attributes,
        ]);
    }

    private function tick(int $percent): DownloadProgress
    {
        return DownloadProgress::fromCallback('song.flac', $percent.'%', '38.40MiB', '2.10MiB/s', '00:12');
    }

    public function test_the_first_report_is_written_immediately(): void
    {
        // Otherwise the row sits at 0% for a full throttle interval, which on a short
        // track is most of the download.
        $record = $this->record();

        (ProgressWriter::for($record))($this->tick(7));

        $this->assertSame(7, $record->refresh()->progress_percent);
    }

    public function test_intermediate_reports_are_throttled_away(): void
    {
        /*
         * yt-dlp emits progress several times a second. Persisting every line would
         * be thousands of UPDATEs per track for a bar the UI polls every three
         * seconds.
         */
        $record = $this->record();
        $writer = ProgressWriter::for($record);

        $writer($this->tick(5));

        DB::enableQueryLog();

        foreach ([6, 7, 8, 9, 10, 11] as $percent) {
            $writer($this->tick($percent));
        }

        $writes = array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_starts_with($query['query'], 'update'),
        );

        DB::disableQueryLog();

        $this->assertSame([], $writes, 'no further writes should have been persisted');
        $this->assertSame(5, $record->refresh()->progress_percent);
    }

    public function test_completion_is_never_throttled_away(): void
    {
        // A bar that stops at 87% because the last line arrived inside the throttle
        // window looks like a stalled download.
        $record = $this->record();
        $writer = ProgressWriter::for($record);

        $writer($this->tick(87));
        $writer($this->tick(100));

        $this->assertSame(100, $record->refresh()->progress_percent);
    }

    public function test_a_write_after_the_throttle_interval_lands(): void
    {
        $record = $this->record();

        // Zero throttle is the honest way to test "enough time passed"; sleeping a
        // real second to prove arithmetic would just make the suite slower.
        config()->set('minizo.downloads.progress_throttle', 0.0);

        $writer = ProgressWriter::for($record);

        $writer($this->tick(10));
        $writer($this->tick(20));

        $this->assertSame(20, $record->refresh()->progress_percent);
    }

    public function test_a_cancel_request_aborts_the_download(): void
    {
        // The whole cancellation mechanism. The request that clicks "x" runs in a different
        // process, so it leaves a note the worker reads here. Throwing is what terminates the
        // yt-dlp child, since a blocking Process run() cannot be interrupted politely.
        $record = $this->record();
        $writer = ProgressWriter::for($record);

        $writer($this->tick(10));

        $record->requestCancel();

        config()->set('minizo.downloads.progress_throttle', 0.0);
        $writer = ProgressWriter::for($record);

        $this->expectException(DownloadCancelled::class);

        $writer($this->tick(20));
    }

    public function test_the_cancel_check_reads_the_database_not_the_stale_model(): void
    {
        // The in-memory model was loaded before the cancel was written, so anything
        // reading it instead of the row would never see the request.
        $record = $this->record();

        DownloadJob::query()->whereKey($record->getKey())->update(['cancel_requested_at' => now()]);

        $this->assertNull($record->cancel_requested_at, 'the in-memory model is stale here');

        $this->expectException(DownloadCancelled::class);

        (ProgressWriter::for($record))($this->tick(10));
    }

    public function test_progress_updated_at_is_bumped_so_the_reaper_can_tell_it_is_alive(): void
    {
        // The stall reaper is the pipeline's only timeout, and this timestamp is the
        // only thing it has to go on.
        $record = $this->record(['progress_updated_at' => now()->subHour()]);

        (ProgressWriter::for($record))($this->tick(30));

        $this->assertTrue($record->refresh()->progress_updated_at->isAfter(now()->subMinute()));
    }
}
