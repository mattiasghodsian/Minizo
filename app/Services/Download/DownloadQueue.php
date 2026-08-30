<?php

namespace App\Services\Download;

use App\Enums\AudioFormat;
use App\Enums\DownloadStatus;
use App\Exceptions\DownloadException;
use App\Jobs\DownloadTrackJob;
use App\Models\DownloadJob;
use App\Models\User;
use App\Services\Library\FolderService;
use App\Support\LibraryFolder;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Gate;

class DownloadQueue
{
    /** Accepts download requests and turns them into queued jobs. */
    public function __construct(
        private FolderService $folders,
    ) {}

    /**
     * Validate, resolve and enqueue.
     *
     * @throws DownloadException for anything the user can see and fix.
     */
    public function push(User $user, string $url, ?string $folder = null, ?AudioFormat $format = null): DownloadJob
    {
        Gate::forUser($user)->authorize('create', DownloadJob::class);

        $url = trim($url);

        if (! $this->looksDownloadable($url)) {
            throw DownloadException::invalidUrl();
        }

        $destination = $this->resolveFolder($user, $folder);

        $job = new DownloadJob;

        $job->forceFill([
            'user_id' => $user->getKey(),
            'url' => $url,
            'folder' => $destination->name,
            'format' => $this->resolveFormat($user, $format),
            'status' => DownloadStatus::Queued,
            'progress_percent' => 0,
        ])->save();

        DownloadTrackJob::dispatch($job);

        return $job;
    }

    /** Where this user's downloads are allowed to land. */
    public function resolveFolder(User $user, ?string $requested): LibraryFolder
    {
        if (filled($user->download_folder_lock)) {
            $locked = $this->folders->find($user->download_folder_lock);

            // Reported rather than created: creating it would add a folder to the
            // library that nobody granted access to.
            if ($locked === null) {
                throw DownloadException::onField('folder', __(
                    'Your downloads are locked to the folder ":name", which no longer exists. Ask an administrator to recreate it.',
                    ['name' => $user->download_folder_lock],
                ));
            }

            return $locked;
        }

        $folder = $this->folders->find($requested);

        if ($folder === null) {
            throw DownloadException::onField('folder', __('Choose a destination folder.'));
        }

        // You may not drop files into a folder you cannot see.
        Gate::forUser($user)->authorize('view', $folder);

        return $folder;
    }

    /** The format lock behaves exactly like the folder lock. With Minizo FLAC-only there is nothing to choose between yet, but the resolution lives here so the screen gains a working select the moment a second case is added to AudioFormat. */
    public function resolveFormat(User $user, ?AudioFormat $requested): AudioFormat
    {
        return $user->download_format_lock
            ?? $requested
            ?? AudioFormat::default();
    }

    /**
     * Folders this user may pick as a destination.
     *
     * @return array<int, string>
     */
    public function destinationsFor(User $user): array
    {
        if (filled($user->download_folder_lock)) {
            $locked = $this->folders->find($user->download_folder_lock);

            return $locked !== null ? [$locked->name] : [];
        }

        return array_map(
            fn (LibraryFolder $folder): string => $folder->name,
            $this->folders->visibleTo($user),
        );
    }

    /**
     * An http(s) URL pointing somewhere on the public internet.
     *
     * The host check is the security half: see App\Support\PublicUrl. It is repeated in
     * DownloadTrackJob, because the answer can change between queueing and fetching.
     */
    private function looksDownloadable(string $url): bool
    {
        return PublicUrl::isSafe($url);
    }
}
