<?php

namespace App\Support;

use App\Models\User;

final readonly class ViewerContext
{
    /** Who is acting, whose view is being rendered, and what they may see. */
    private function __construct(
        /** The signed-in user. Authority always comes from here. */
        public User $actor,

        /** The user whose view is being rendered. Visibility comes from here. */
        public User $subject,

        /** The subject's folder access. */
        public FolderAccess $access,
    ) {}

    /** A user looking at their own view. */
    public static function self(User $user): self
    {
        return new self($user, $user, FolderAccess::fromUser($user));
    }

    /** An admin previewing another user's view. */
    public static function previewing(User $actor, User $subject): self
    {
        return new self($actor, $subject, FolderAccess::fromUser($subject));
    }

    /** Whether an admin is rendering the app as someone else. */
    public function isPreview(): bool
    {
        return $this->actor->getKey() !== $this->subject->getKey();
    }

    /** Permissions are always the ACTOR's. */
    public function permissions(): Permissions
    {
        return $this->actor->permissions();
    }
}
