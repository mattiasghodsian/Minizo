<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\DownloadJob;
use App\Models\User;

class DownloadJobPolicy
{
    /** May this user queue a download? */
    public function create(User $user): bool
    {
        return $user->permissions()->effective(Permission::Downloader);
    }

    /** Owner or admin. */
    public function view(User $user, DownloadJob $job): bool
    {
        return $user->isAdmin() || $job->user_id === $user->getKey();
    }

    /** Stopping something already finished is meaningless, so the terminal check lives here rather than in the component - it is part of "may I". */
    public function cancel(User $user, DownloadJob $job): bool
    {
        return $this->view($user, $job) && ! $job->status->isTerminal();
    }

    /** Hiding a row only affects what the queue widget shows, so it needs no more than ownership - and unlike cancel it is only meaningful once finished. */
    public function hide(User $user, DownloadJob $job): bool
    {
        return $this->view($user, $job);
    }
}
