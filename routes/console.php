<?php

use App\Models\Share;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The download pipeline's only timeout.
 *
 * youtube-dl-php cannot set a process timeout, so a wedged yt-dlp child is
 * detected here or not at all — which is why this runs every five minutes rather
 * than nightly. The same command prunes finished rows past their retention.
 *
 * withoutOverlapping() because two invocations would race on the same rows, and
 * the command is idempotent so skipping a run costs nothing.
 */
Schedule::command('minizo:downloads:reap')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * Share retention.
 *
 * Dead links are kept for minizo.shares.retention_days so the Share links screen can
 * answer "what was shared last week", then removed. Daily is the right cadence: the
 * cutoff is measured in days, and Share::pruning() logs each deletion so the retention
 * promise stays auditable rather than being enforced silently.
 *
 * Note this prunes nothing that is still live — expiry is computed from expires_at on
 * every read, so a link stops working the instant it lapses, with no job involved.
 */
Schedule::command('model:prune', ['--model' => [Share::class]])
    ->daily()
    ->withoutOverlapping();

/*
 * Followed artists' new releases.
 *
 * Hourly, and it queues jobs rather than making requests itself — so the RateLimited and
 * WithoutOverlapping middleware apply. A scheduled command that called Tidal directly
 * would bypass both and could spend the whole request budget in one tick.
 *
 * Each run takes only a batch of the artists whose data has gone stale (see
 * minizo.feed.sync_batch and resync_after_minutes), so the work spreads across the hour
 * instead of arriving as a spike. A newly followed artist does not wait for this at all —
 * following dispatches its own job immediately.
 */
Schedule::command('minizo:feed:sync')
    ->hourly()
    ->withoutOverlapping();
