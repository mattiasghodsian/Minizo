<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

final class NewUserDefaults
{
    /** Grant the appropriate starting privileges and persist them. */
    public static function apply(User $user): User
    {
        return self::isFirstUser($user)
            ? self::promoteToAdmin($user)
            : self::lockDown($user);
    }

    /** Full access: admin, every permission, all folders. */
    public static function promoteToAdmin(User $user): User
    {
        $user->forceFill([
            'role' => Role::Admin,
            'is_active' => true,
            'folder_access' => FolderAccess::all()->toArray(),
            ...Permissions::all()->toColumns(),
        ])->save();

        return $user;
    }

    /** A new non-admin can sign in and change their own profile, and nothing else. */
    public static function lockDown(User $user): User
    {
        $user->forceFill([
            'role' => Role::User,
            'is_active' => true,
            'folder_access' => FolderAccess::none()->toArray(),
            ...Permissions::none()->toColumns(),
            'download_folder_lock' => null,
            'download_format_lock' => null,
        ])->save();

        return $user;
    }

    /** Whether this is the only account that exists. */
    private static function isFirstUser(User $user): bool
    {
        return ! User::query()
            ->when($user->exists, fn ($query) => $query->whereKeyNot($user->getKey()))
            ->exists();
    }
}
