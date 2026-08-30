<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    /** May this user open the Users screen? */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /** May this user see an account, their own or anyone else? */
    public function view(User $user, User $subject): bool
    {
        return $user->isAdmin() || $user->is($subject);
    }

    /** May this user add an account? */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /** May this user change an account name or email? */
    public function update(User $user, User $subject): bool
    {
        return $user->isAdmin();
    }

    /** Toggling "Account active". */
    public function setActive(User $user, User $subject): bool
    {
        return $user->isAdmin() && ! $user->is($subject);
    }

    /** Changing role, folder access, permissions or downloader locks. */
    public function setPermissions(User $user, User $subject): bool
    {
        return $user->isAdmin() && ! $user->is($subject);
    }

    /**
     * May this user delete someone else's account? Never their own.
     *
     * Deleting your OWN account is a different question, answered by deleteSelf(): it
     * needs no admin rights, only a password, and is reachable from Settings.
     */
    public function delete(User $user, User $subject): bool
    {
        return $user->isAdmin() && ! $user->is($subject);
    }

    /**
     * May this user delete their own account?
     *
     * Anyone may, except the last remaining admin. Every administrative gate resolves
     * isAdmin(), so an instance with no admin left cannot create users, manage folders or
     * touch the sharing switch, and NewUserDefaults only auto-promotes into an empty
     * table - so it does not heal itself while other accounts exist. Recovering means
     * shell access and minizo:make-admin.
     */
    public function deleteSelf(User $user): bool
    {
        if (! $user->isAdmin()) {
            return true;
        }

        // is_active as well as the role: a deactivated admin cannot sign in, so leaving
        // one behind would still lock the instance.
        return User::query()
            ->where('role', Role::Admin)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->exists();
    }

    /** May this user render the app as another user sees it? */
    public function previewAs(User $user, User $subject): bool
    {
        return $user->isAdmin();
    }
}
