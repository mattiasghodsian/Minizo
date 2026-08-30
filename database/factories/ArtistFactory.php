<?php

namespace Database\Factories;

use App\Models\Artist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Artist>
 */
class ArtistFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->name();

        return [
            'provider' => 'tidal',
            'provider_id' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'name' => $name,
            'name_key' => Artist::key($name),
            'image_url' => 'https://resources.tidal.com/images/'.fake()->uuid().'/320x320.jpg',
            'popularity' => fake()->numberBetween(0, 100),
            'last_synced_at' => null,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $name,
            'name_key' => Artist::key($name),
        ]);
    }

    /**
     * Synced recently enough that scopeDueForSync skips it.
     */
    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_synced_at' => now(),
        ]);
    }

    /**
     * Stale enough to be queued for a refresh.
     */
    public function stale(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_synced_at' => now()->subMinutes((int) config('minizo.feed.resync_after_minutes', 360) + 30),
        ]);
    }

    public function withoutImage(): static
    {
        return $this->state(fn (array $attributes) => ['image_url' => null]);
    }
}
