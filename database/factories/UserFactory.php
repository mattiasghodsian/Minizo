<?php

namespace Database\Factories;

use App\Enums\AudioFormat;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Support\FolderAccess;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,

            /*
             * The default factory user is an ordinary account with every
             * permission and access to all folders.
             *
             * That is deliberately NOT the production default (a real new account
             * gets nothing — see App\Support\NewUserDefaults). It is chosen so the
             * ~40 pre-existing tests, which predate permissions entirely, keep
             * exercising the features they were written for. Tests about
             * restriction opt in explicitly via viewOnly(), withFolders() or
             * without().
             */
            'role' => Role::User,
            'is_active' => true,
            'folder_access' => FolderAccess::all()->toArray(),
            ...Permissions::all()->toColumns(),
            'download_folder_lock' => null,
            'download_format_lock' => null,
            'pagination_size' => 50,
        ];
    }

    /**
     * An administrator: manages users and folders, sees everything.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Admin,
            'folder_access' => FolderAccess::all()->toArray(),
            ...Permissions::all()->toColumns(),
        ]);
    }

    /**
     * Granted nothing: the design's "View only" summary.
     */
    public function viewOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::User,
            ...Permissions::none()->toColumns(),
        ]);
    }

    /**
     * Granted exactly these permissions and nothing else.
     *
     * @param  array<int, Permission>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn (array $attributes) => Permissions::of($permissions)->toColumns());
    }

    /**
     * Granted everything except these.
     *
     * @param  array<int, Permission>  $permissions
     */
    public function without(array $permissions): static
    {
        $remaining = array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => ! in_array($permission, $permissions, true),
        ));

        return $this->withPermissions($remaining);
    }

    /**
     * Access limited to these folder names.
     *
     * @param  array<int, string>  $folders
     */
    public function withFolders(array $folders): static
    {
        return $this->state(fn (array $attributes) => [
            'folder_access' => FolderAccess::of($folders)->toArray(),
        ]);
    }

    /**
     * No folder access at all.
     */
    public function withoutFolders(): static
    {
        return $this->state(fn (array $attributes) => [
            'folder_access' => FolderAccess::none()->toArray(),
        ]);
    }

    /**
     * A deactivated account. "Disabled users cannot log in."
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Downloads forced into one folder, and optionally one format.
     */
    public function lockedDownloader(string $folder, ?AudioFormat $format = null): static
    {
        return $this->state(fn (array $attributes) => [
            'can_downloader' => true,
            'download_folder_lock' => $folder,
            'download_format_lock' => $format,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
