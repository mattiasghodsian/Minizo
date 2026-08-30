<?php

namespace Database\Factories;

use App\Enums\AudioFormat;
use App\Enums\DownloadStatus;
use App\Models\DownloadJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DownloadJob>
 */
class DownloadJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'url' => 'https://music.youtube.com/watch?v='.fake()->regexify('[A-Za-z0-9_-]{11}'),
            'folder' => 'Spanish',
            'format' => AudioFormat::Flac,
            'status' => DownloadStatus::Queued,
            'progress_percent' => 0,
        ];
    }

    public function forFolder(string $folder): static
    {
        return $this->state(fn (array $attributes) => ['folder' => $folder]);
    }

    public function running(int $percent = 42): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DownloadStatus::Running,
            'progress_percent' => $percent,
            'speed_label' => '2.10MiB/s',
            'eta_label' => '00:12',
            'size_label' => '38.40MiB',
            'title' => 'Monaco',
            'artist' => 'Bad Bunny',
            'started_at' => now()->subSeconds(20),
            'progress_updated_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DownloadStatus::Completed,
            'progress_percent' => 100,
            'title' => 'Monaco',
            'artist' => 'Bad Bunny',
            'filename' => 'Bad Bunny - Monaco.flac',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'progress_updated_at' => now(),
        ]);
    }

    public function failed(string $error = 'Video unavailable'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DownloadStatus::Failed,
            'error' => $error,
            'finished_at' => now(),
            'progress_updated_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DownloadStatus::Cancelled,
            'cancel_requested_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /**
     * Running, but its progress went stale — what the reaper is looking for.
     */
    public function stalled(): static
    {
        $stale = now()->subSeconds((int) config('minizo.downloads.stall_timeout', 900) + 60);

        return $this->running()->state(fn (array $attributes) => [
            'progress_updated_at' => $stale,
            // Started before it went quiet, which is the only shape a real stalled
            // row can have.
            'started_at' => $stale->copy()->subMinute(),
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => ['hidden_at' => now()]);
    }
}
