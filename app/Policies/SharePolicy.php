<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Share;
use App\Models\User;

class SharePolicy
{
    /** Everyone with the Share permission sees the Share links screen. */
    public function viewAny(User $user): bool
    {
        // granted(), not effective(): turning the switch off must not hide the audit
        // tool. That is exactly when someone most wants to look at what is still live.
        return $user->permissions()->granted(Permission::Share);
    }

    /**
     * May this user see one link on the Share links screen?
     *
     * Owner or admin, not merely "holds the Share permission". The screen renders the
     * working URL with a copy button, and that URL is the capability: it bypasses
     * folder_access by design, because a stranger holding it has no account at all. So
     * seeing someone else's link would hand out access to a folder this user may never
     * have been granted.
     */
    public function view(User $user, Share $share): bool
    {
        return $this->viewAny($user)
            && ($user->isAdmin() || $share->user_id === $user->getKey());
    }

    /** Killing a link: the owner, or an admin. */
    public function revoke(User $user, Share $share): bool
    {
        return $this->view($user, $share);
    }

    /** May this user filter the list by owner, or see the owner pills at all? */
    public function viewAnyOwner(User $user): bool
    {
        return $this->viewAny($user) && $user->isAdmin();
    }

    /** Removing a dead row from the audit list. */
    public function delete(User $user, Share $share): bool
    {
        return $this->revoke($user, $share);
    }
}
