<?php

namespace App\Providers;

use App\Models\DownloadJob;
use App\Models\Share;
use App\Models\User;
use App\Policies\DownloadJobPolicy;
use App\Policies\LibraryFilePolicy;
use App\Policies\LibraryFolderPolicy;
use App\Policies\SharePolicy;
use App\Policies\UserPolicy;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Policies, including two bound to plain value objects.
     *
     * @var array<class-string, class-string>
     */
    private const POLICIES = [
        LibraryFolder::class => LibraryFolderPolicy::class,
        LibraryFile::class => LibraryFilePolicy::class,
        User::class => UserPolicy::class,
        DownloadJob::class => DownloadJobPolicy::class,
        Share::class => SharePolicy::class,
    ];

    /** Register the policies and the passkey settings. */
    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerGates();
    }

    /** Abilities with no subject. */
    private function registerGates(): void
    {
        Gate::define('manage-users', fn (User $user): bool => $user->isAdmin());

        // Creating, renaming and deleting folders changes the library for everyone,
        // including which names other users' folder_access refers to.
        Gate::define('manage-folders', fn (User $user): bool => $user->isAdmin());

        // The instance-wide public-sharing kill switch.
        Gate::define('toggle-sharing', fn (User $user): bool => $user->isAdmin());

        // The admin-only "viewing as" pills on Download and Feed.
        Gate::define('preview-other-users', fn (User $user): bool => $user->isAdmin());
    }
}
