<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;

class LibraryFilePolicy
{
    /** May this user see the file, meaning the folder it sits in? */
    public function view(User $user, LibraryFile $file): bool
    {
        return $user->folderAccess()->allows($file->folder->name);
    }

    /** May this user rewrite the file tags? */
    public function editMetadata(User $user, LibraryFile $file): bool
    {
        return $this->view($user, $file)
            && $user->permissions()->effective(Permission::Edit);
    }

    /** Moving needs access to BOTH ends. */
    public function move(User $user, LibraryFile $file, LibraryFolder $destination): bool
    {
        return $this->view($user, $file)
            && $user->folderAccess()->allows($destination->name)
            && $user->permissions()->effective(Permission::Move);
    }

    /** May this user download the file? */
    public function download(User $user, LibraryFile $file): bool
    {
        return $this->view($user, $file)
            && $user->permissions()->effective(Permission::Download);
    }

    /** May this user delete the file? */
    public function delete(User $user, LibraryFile $file): bool
    {
        return $this->view($user, $file)
            && $user->permissions()->effective(Permission::Delete);
    }

    /** May this user create a public link to the file? */
    public function share(User $user, LibraryFile $file): bool
    {
        return $this->view($user, $file)
            && $user->permissions()->effective(Permission::Share);
    }
}
