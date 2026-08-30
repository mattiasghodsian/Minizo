<?php

use App\Enums\AudioFormat;
use App\Enums\DownloadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minizo's own record of a download.
 *
 * This exists because the legacy app had nothing like it: to render the queue it
 * read the framework's `jobs` table with `payload LIKE '%DownloadJob%'` and
 * `unserialize()`d the payload. That gave no progress, no failure reason, and no
 * history at all — a finished download simply vanished, because the framework
 * deletes its own row on success.
 *
 * One table therefore serves both halves of the Download screen: the live queue
 * (non-terminal rows) and Recent activity (terminal rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_jobs', function (Blueprint $table) {
            $table->id();

            // Deleting an account takes its download history with it. The queue
            // rows are meaningless without the owner, and Recent activity is
            // per-user by design.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('url');

            /*
             * The destination folder NAME, not a path — the library is exactly one
             * level deep. Like users.folder_access this is a by-name reference to a
             * directory with no database row, so an in-app folder rename has to fan
             * out here too; FolderService owns that.
             */
            $table->string('folder');

            $table->string('format', 16)->default(AudioFormat::default()->value);

            $table->string('status', 16)->default(DownloadStatus::Queued->value);

            // 0-100. yt-dlp reports a percentage per output file and restarts it for
            // the audio-extraction pass, so this is "progress of the current step",
            // which is also what the design's single bar shows.
            $table->unsignedTinyInteger('progress_percent')->default(0);

            /*
             * Progress is stored as the labels yt-dlp gave us, not as parsed
             * numbers. It reports MiB and MiB/s; converting to MB to make the
             * numbers rounder would quietly misreport the size, and nothing in the
             * app does arithmetic on these — they are only ever displayed.
             */
            $table->string('speed_label', 32)->nullable();
            $table->string('eta_label', 32)->nullable();
            $table->string('size_label', 32)->nullable();

            // Filled in from yt-dlp's metadata once it knows what it is fetching.
            // Until then the queue row falls back to showing the URL.
            $table->string('title')->nullable();
            $table->string('artist')->nullable();
            $table->string('filename')->nullable();

            $table->text('error')->nullable();

            /*
             * Cancellation is cooperative: there is no way to signal a running
             * yt-dlp process from another PHP request, so the UI sets this and the
             * worker notices on its next progress write and aborts.
             */
            $table->timestamp('cancel_requested_at')->nullable();

            /*
             * Bumped on every persisted progress write, which is what makes a
             * wedged download detectable. The youtube-dl-php library exposes no
             * process timeout, so `minizo:downloads:reap` comparing this against
             * now() is the only thing standing between a stalled child process and
             * a permanently occupied worker slot.
             */
            $table->timestamp('progress_updated_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // The queue's "×" on a finished row hides it without destroying the
            // history that Recent activity is built from.
            $table->timestamp('hidden_at')->nullable();

            $table->timestamps();

            // The queue widget: this user's non-terminal rows.
            $table->index(['user_id', 'status']);

            // The stall reaper: running rows ordered by staleness.
            $table->index(['status', 'progress_updated_at']);

            // Recent activity, and pruning old history.
            $table->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_jobs');
    }
};
