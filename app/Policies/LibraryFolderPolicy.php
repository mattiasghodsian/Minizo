<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\LibraryFolder;

class LibraryFolderPolicy
{
    /** May this user see the folder at all? */
    public function view(User $user, LibraryFolder $folder): bool
    {
        return $user->folderAccess()->allows($folder->name);
    }

    /** May this user add a folder to the library? */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /** May this user rename the folder? */
    public function rename(User $user, LibraryFolder $folder): bool
    {
        return $user->isAdmin();
    }

    /** May this user delete the folder and its contents? */
    public function delete(User $user, LibraryFolder $folder): bool
    {
        return $user->isAdmin();
    }

    /** May this user create a public link to the whole folder? */
    public function share(User $user, LibraryFolder $folder): bool
    {
        return $this->view($user, $folder)
            && $user->permissions()->effective(Permission::Share);
    }

    /** May this user download the folder as a zip? */
    public function downloadArchive(User $user, LibraryFolder $folder): bool
    {
        return $this->view($user, $folder)
            && $user->permissions()->effective(Permission::Download);
    }
}
