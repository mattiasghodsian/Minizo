<?php

namespace App\Services\Sharing;

use App\Enums\ShareExpiry;
use App\Enums\ShareType;
use App\Exceptions\ShareException;
use App\Models\Share;
use App\Models\User;
use App\Services\Library\FileService;
use App\Services\Library\FolderService;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use App\Support\Sharing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ShareService
{
    /** Creates, resolves and revokes public share links. */
    public function __construct(
        private FolderService $folders,
        private FileService $files,
        private ShareTokenGenerator $tokens,
    ) {}

    /**
     * Publish a folder.
     *
     * @throws ShareException
     */
    public function shareFolder(User $user, LibraryFolder $folder, ShareExpiry $expiry): Share
    {
        Gate::forUser($user)->authorize('share', $folder);

        $this->assertEnabled();

        $resolved = $this->folders->find($folder->name)
            ?? throw ShareException::folderMissing($folder->name);

        // Refused: a folder share that lists nothing and zips to an empty archive reads
        // as broken to whoever receives it. allUnguarded because the Gate::forUser check
        // above is the authorization; there is no ambient user here.
        if ($this->files->allUnguarded($resolved) === []) {
            throw ShareException::emptyFolder($resolved->name);
        }

        return $this->create($user, ShareType::Folder, $resolved->name, $resolved->name, null, $expiry);
    }

    /**
     * Publish one track.
     *
     * @throws ShareException
     */
    public function shareFile(User $user, LibraryFile $file, ShareExpiry $expiry): Share
    {
        Gate::forUser($user)->authorize('share', $file);

        $this->assertEnabled();

        $resolved = $this->files->findUnguarded($file->folder, $file->filename)
            ?? throw ShareException::fileMissing($file->filename);

        return $this->create(
            user: $user,
            type: ShareType::Track,
            // The label drops the extension, as the design does: ".flac" is noise on a
            // page whose entire subject is one audio file.
            name: $resolved->basename(),
            folder: $resolved->folder->name,
            filename: $resolved->filename,
            expiry: $expiry,
        );
    }

    /** Resolve a public token. */
    public function resolve(string $token): ?Share
    {
        $share = Share::query()->where('token', $token)->first();

        if ($share === null || $share->isDead()) {
            return null;
        }

        return $share;
    }

    /**
     * The files a share exposes, in the order the public page lists them.
     *
     * @return array<int, LibraryFile>
     */
    public function contents(Share $share): array
    {
        $folder = $share->libraryFolder();

        if ($share->type === ShareType::Track) {
            $file = $this->files->findUnguarded($folder, (string) $share->filename);

            return $file !== null ? [$file] : [];
        }

        // allUnguarded, because a public request has no authenticated user for
        // Gate::authorize('view') to consult. The share row is the authorization.
        return $this->files->allUnguarded($folder);
    }

    // ------------------------------------------------------------------ lifecycle

    /** Stop one link resolving, keeping the row. */
    public function revoke(Share $share): void
    {
        $share->revoke();
    }

    /**
     * Kill every live link a user published, when their account is disabled.
     *
     * @return int links revoked
     */
    public function revokeForUser(User $user): int
    {
        if (! (bool) config('minizo.sharing.revoke_on_user_disable', false)) {
            return 0;
        }

        $revoked = Share::query()
            ->where('user_id', $user->getKey())
            ->live()
            ->update(['revoked_at' => now()]);

        if ($revoked > 0) {
            Log::info('Revoked share links for a disabled account', [
                'user_id' => $user->getKey(),
                'count' => $revoked,
            ]);
        }

        return $revoked;
    }

    /** Follow a folder rename, so links keep working. */
    public function renameFolder(LibraryFolder $from, LibraryFolder $to): int
    {
        return Share::query()->forFolder($from)->update(['folder' => $to->name]);
    }

    /** Kill every link into a folder that no longer exists. */
    public function revokeForFolder(LibraryFolder $folder): int
    {
        $revoked = Share::query()->forFolder($folder)->live()->update(['revoked_at' => now()]);

        if ($revoked > 0) {
            Log::info('Revoked share links for a deleted folder', [
                'folder' => $folder->name,
                'count' => $revoked,
            ]);
        }

        return $revoked;
    }

    /** Kill every link a track share pointed at, when the file itself has gone. */
    public function revokeForFile(LibraryFile $file): int
    {
        return Share::query()
            ->forFolder($file->folder)
            ->where('type', ShareType::Track)
            ->where('filename', $file->filename)
            ->live()
            ->update(['revoked_at' => now()]);
    }

    /** Follow a file rename. */
    public function renameFile(LibraryFile $from, LibraryFile $to): int
    {
        return Share::query()
            ->forFolder($from->folder)
            ->where('type', ShareType::Track)
            ->where('filename', $from->filename)
            ->update(['filename' => $to->filename]);
    }

    /** Follow a file move between folders. */
    public function moveFile(LibraryFile $from, LibraryFile $to): int
    {
        return Share::query()
            ->forFolder($from->folder)
            ->where('type', ShareType::Track)
            ->where('filename', $from->filename)
            ->update(['folder' => $to->folder->name]);
    }

    // ------------------------------------------------------------------ internals

    /**
     * @throws ShareException
     */
    private function assertEnabled(): void
    {
        // Re-checked here as well as in the policy. The policy answers "may this user",
        // this answers "is the feature on at the moment the row is written" - and a
        // request that started before an admin flipped the switch must not slip past it.
        if (! Sharing::enabled()) {
            throw ShareException::disabled();
        }
    }

    /** Persist a share row with a fresh token and an expiry. */
    private function create(
        User $user,
        ShareType $type,
        string $name,
        string $folder,
        ?string $filename,
        ShareExpiry $expiry,
    ): Share {
        $share = new Share;

        $share->forceFill([
            'user_id' => $user->getKey(),
            'token' => $this->tokens->generate(),
            'type' => $type,
            'name' => $name,
            'folder' => $folder,
            'filename' => $filename,
            'expires_at' => $expiry->toDate(),
        ])->save();

        Log::info('Share link created', [
            'share_id' => $share->getKey(),
            'user_id' => $user->getKey(),
            'type' => $type->value,
            'folder' => $folder,
            'expires_at' => (string) $share->expires_at,
        ]);

        return $share;
    }
}
