<?php

namespace Database\Factories;

use App\Enums\ReleaseType;
use App\Models\Artist;
use App\Models\ArtistRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArtistRelease>
 */
class ArtistReleaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artist_id' => Artist::factory(),
            'provider_id' => (string) fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'title' => fake()->words(3, true),
            'release_type' => fake()->randomElement([ReleaseType::Album, ReleaseType::Ep, ReleaseType::Single]),
            'released_on' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'cover_url' => 'https://resources.tidal.com/images/'.fake()->uuid().'/320x320.jpg',
            'link' => 'https://tidal.com/browse/album/'.fake()->numberBetween(1_000_000, 9_999_999),
            'first_seen_at' => now(),
        ];
    }

    /**
     * Seen just now, so it is new to anyone who has not looked since.
     */
    public function justArrived(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_seen_at' => now(),
            'released_on' => now()->subDays(2)->toDateString(),
        ]);
    }

    /**
     * First seen longer ago than new_for_days, so it can never read as new.
     */
    public function old(): static
    {
        $days = (int) config('minizo.feed.new_for_days', 14);

        return $this->state(fn (array $attributes) => [
            'first_seen_at' => now()->subDays($days + 5),
            'released_on' => now()->subMonths(8)->toDateString(),
        ]);
    }

    /**
     * No release date — Tidal omits it on some pre-release and regional entries, and the
     * ordering has to cope.
     */
    public function undated(): static
    {
        return $this->state(fn (array $attributes) => ['released_on' => null]);
    }

    public function type(ReleaseType $type): static
    {
        return $this->state(fn (array $attributes) => ['release_type' => $type]);
    }
}
