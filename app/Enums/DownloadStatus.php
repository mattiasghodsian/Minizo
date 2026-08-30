<?php

namespace App\Enums;

/** Lifecycle of a row in `download_jobs`. */
enum DownloadStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** How the status reads on a queue row. */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Downloading',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether the job has stopped moving. Terminal rows are never picked up by the stall reaper and are what the "Recent activity" table reads. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            self::Completed, self::Failed, self::Cancelled => true,
        };
    }

    /** Whether a progress bar should be shown and animated. */
    public function isActive(): bool
    {
        return $this === self::Running;
    }

    /** The design token this status renders in - see resources/css/app.css. */
    public function tone(): string
    {
        return match ($this) {
            self::Queued => 'progress-queued',
            self::Running => 'brand',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'ink-faint',
        };
    }

    /** The full text-colour utility for the status label. */
    public function textClass(): string
    {
        return match ($this) {
            self::Queued => 'text-ink-muted!',
            self::Running => 'text-brand-text!',
            self::Completed => 'text-success!',
            self::Failed => 'text-danger!',
            self::Cancelled => 'text-ink-faint!',
        };
    }

    /**
     * Statuses the live queue widget shows.
     *
     * @return array<int, self>
     */
    public static function inFlight(): array
    {
        return [self::Queued, self::Running];
    }
}
