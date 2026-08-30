<?php

namespace Database\Factories;

use App\Enums\ShareExpiry;
use App\Enums\ShareType;
use App\Models\Share;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Share>
 */
class ShareFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => Str::random(12),
            'type' => ShareType::Folder,
            'name' => 'Spanish',
            'folder' => 'Spanish',
            'filename' => null,
            'expires_at' => ShareExpiry::OneDay->toDate(),
        ];
    }

    public function folder(string $folder): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ShareType::Folder,
            'name' => $folder,
            'folder' => $folder,
            'filename' => null,
        ]);
    }

    public function track(string $folder, string $filename): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ShareType::Track,
            // The design shows the track's name, not the file's — the extension is noise
            // on a page whose whole subject is one audio file.
            'name' => pathinfo($filename, PATHINFO_FILENAME),
            'folder' => $folder,
            'filename' => $filename,
        ]);
    }

    /**
     * Past its expiry, but not revoked — the "Expired" row.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subHour(),
        ]);
    }

    /**
     * Killed by hand — the "Revoked" row, which reads differently from "Expired".
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now()->subMinute(),
        ]);
    }

    /**
     * Dead long enough to be prunable.
     */
    public function stale(): static
    {
        $days = (int) config('minizo.shares.retention_days', 30);

        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDays($days + 1),
        ]);
    }

    /**
     * Inside the amber threshold, so the countdown pill turns warning.
     */
    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addMinutes(20),
        ]);
    }
}
