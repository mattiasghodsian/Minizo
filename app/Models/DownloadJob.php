<?php

namespace App\Models;

use App\Enums\AudioFormat;
use App\Enums\DownloadStatus;
use App\Support\LibraryFolder;
use Database\Factories\DownloadJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $url
 * @property string $folder
 * @property AudioFormat $format
 * @property DownloadStatus $status
 * @property int $progress_percent
 * @property string|null $speed_label
 * @property string|null $eta_label
 * @property string|null $size_label
 * @property string|null $title
 * @property string|null $artist
 * @property string|null $filename
 * @property string|null $error
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $progress_updated_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $hidden_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class DownloadJob extends Model
{
    /** @use HasFactory<DownloadJobFactory> */
    use HasFactory;

    /**
     * Nothing is mass-assignable. Every write goes through DownloadQueue or one of the transition methods below, because `folder` and `format` are the result of applying an admin's downloader locks - never of a request payload.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => AudioFormat::class,
            'status' => DownloadStatus::class,
            'progress_percent' => 'integer',
            'cancel_requested_at' => 'datetime',
            'progress_updated_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ------------------------------------------------------------------ scopes

    /**
     * Still moving, or waiting to.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', DownloadStatus::inFlight());
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', DownloadStatus::Completed);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }

    /**
     * Rows the Download screen's queue widget shows: everything in flight, plus anything that finished recently enough to still be worth looking at.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeQueueWidget(Builder $query): void
    {
        $linger = now()->subSeconds((int) config('minizo.downloads.queue_linger', 600));

        $query->visible()->where(function (Builder $query) use ($linger): void {
            $query->inFlight()->orWhere(fn (Builder $query) => $query->where('finished_at', '>=', $linger));
        });
    }

    // ------------------------------------------------------------- transitions

    /** A worker picked the job up. */
    public function markRunning(): void
    {
        $this->forceFill([
            'status' => DownloadStatus::Running,
            'started_at' => $this->started_at ?? now(),
            'progress_updated_at' => now(),
            'error' => null,
        ])->save();
    }

    /** The file landed. Records what yt-dlp finally reported. */
    public function markCompleted(string $filename, ?string $title = null, ?string $artist = null): void
    {
        $this->forceFill([
            'status' => DownloadStatus::Completed,
            'filename' => $filename,
            'title' => $title ?? $this->title,
            'artist' => $artist ?? $this->artist,
            'progress_percent' => 100,
            'speed_label' => null,
            'eta_label' => null,
            'finished_at' => now(),
            'progress_updated_at' => now(),
        ])->save();
    }

    /** The download failed, with the reason to show on the row. */
    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => DownloadStatus::Failed,
            // The column is text, but yt-dlp can emit a wall of output on failure
            // and the UI shows this in a one-line row.
            'error' => mb_substr($error, 0, 2000),
            'finished_at' => now(),
            'progress_updated_at' => now(),
        ])->save();
    }

    /** The worker acted on a cancel request. */
    public function markCancelled(): void
    {
        $this->forceFill([
            'status' => DownloadStatus::Cancelled,
            'finished_at' => now(),
            'progress_updated_at' => now(),
            'cancel_requested_at' => $this->cancel_requested_at ?? now(),
        ])->save();
    }

    /** Ask a running worker to stop. */
    public function requestCancel(): void
    {
        $this->forceFill(['cancel_requested_at' => now()])->save();
    }

    /** Drop the row from the queue widget without touching history. */
    public function hide(): void
    {
        $this->forceFill(['hidden_at' => now()])->save();
    }

    /** Whether someone has asked this job to stop. */
    public function cancelRequested(): bool
    {
        return $this->cancel_requested_at !== null;
    }

    // ----------------------------------------------------------- presentation

    /** The folder the file is being written into. */
    public function destination(): LibraryFolder
    {
        return new LibraryFolder($this->folder);
    }

    /** What the queue row and the tile letter read from. */
    public function displayTitle(): string
    {
        if (filled($this->artist) && filled($this->title)) {
            return $this->artist.' - '.$this->title;
        }

        return $this->title ?? $this->filename ?? $this->url;
    }

    /** The right-hand mono label in a queue row. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            DownloadStatus::Running => filled($this->speed_label)
                ? $this->progress_percent.'% · '.$this->speed_label
                : $this->progress_percent.'%',
            DownloadStatus::Failed => __('Failed'),
            default => $this->status->label(),
        };
    }

    /** Whether the queue row's "×" cancels the download or just hides the row. */
    public function isCancellable(): bool
    {
        return ! $this->status->isTerminal();
    }
}
