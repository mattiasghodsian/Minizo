<?php

namespace App\Console\Commands;

use App\Models\DownloadJob;
use App\Models\Share;
use App\Models\User;
use App\Services\Library\FolderService;
use App\Support\FolderAccess;
use Illuminate\Console\Command;

class MinizoLibraryAudit extends Command
{
    protected $signature = 'minizo:library:audit
        {--prune : Revoke shares and drop folder-access entries that point nowhere}';

    protected $description = 'Find database references to library folders that no longer exist';

    /** Report every database reference to a folder that is not on disk. */
    public function handle(FolderService $folders): int
    {
        // all(), not visibleTo(): a console tool has no user to scope by, and scoping
        // would report an invisible folder as a missing one.
        $existing = array_map(fn ($folder): string => $folder->name, $folders->all());

        $this->components->info(sprintf(
            '%d folder(s) on disk: %s',
            count($existing),
            $existing === [] ? '(none)' : implode(', ', $existing),
        ));

        $shares = $this->auditShares($existing);
        $access = $this->auditFolderAccess($existing);
        $jobs = $this->auditDownloadJobs($existing);

        $total = $shares + $access + $jobs;

        $this->newLine();

        if ($total === 0) {
            $this->components->info('Nothing dangling. Every reference resolves to a folder on disk.');

            return self::SUCCESS;
        }

        if ($this->option('prune')) {
            $this->components->info(sprintf('Pruned %d reference(s).', $shares + $access));

            if ($jobs > 0) {
                $this->components->warn(sprintf(
                    '%d download history row(s) still reference a missing folder. See --help.',
                    $jobs,
                ));
            }

            return self::SUCCESS;
        }

        $this->components->warn(sprintf('%d dangling reference(s). Re-run with --prune to act on them.', $total));

        // Non-zero so this is usable from cron or a health check.
        return self::FAILURE;
    }

    /**
     * Report live share links whose folder is gone, revoking them under --prune.
     *
     * @param  array<int, string>  $existing
     */
    private function auditShares(array $existing): int
    {
        $dangling = Share::query()
            ->live()
            ->whereNotIn('folder', $existing)
            ->get();

        if ($dangling->isEmpty()) {
            return 0;
        }

        $this->newLine();

        // Header in the block, detail on plain lines: a components block wraps and pads
        // to the terminal width, which splits long folder names mid-word.
        $this->components->error(sprintf('%d live share link(s) point at a missing folder', $dangling->count()));

        foreach ($dangling as $share) {
            $this->line(sprintf('  <fg=gray>%s</>  %s', $share->token, $share->name));
            $this->line(sprintf(
                '    <fg=gray>→</> %s',
                $share->folder.($share->filename !== null ? '/'.$share->filename : ''),
            ));
        }

        if ($this->option('prune')) {
            // Revoked rather than deleted, so the retention-window audit trail survives.
            Share::query()->live()->whereNotIn('folder', $existing)->update(['revoked_at' => now()]);

            $this->components->info('Revoked. The links now show the expired page rather than 404ing.');
        }

        return $dangling->count();
    }

    /**
     * Report grants pointing at folders that are gone, trimming them under --prune.
     *
     * @param  array<int, string>  $existing
     */
    private function auditFolderAccess(array $existing): int
    {
        $affected = 0;

        // Filtered in PHP rather than SQL: folder_access is JSON, and the query would
        // need a different form per driver.
        foreach (User::query()->whereNotNull('folder_access')->get() as $user) {
            $access = FolderAccess::fromUser($user);

            // The `*` sentinel means "everything that exists", so it can never dangle.
            if ($access->allowsAll()) {
                continue;
            }

            $stale = array_values(array_diff((array) $user->folder_access, $existing));

            if ($stale === []) {
                continue;
            }

            $affected++;

            $this->newLine();

            $this->components->warn(sprintf(
                '%s has access to %d folder(s) that no longer exist',
                $user->email,
                count($stale),
            ));

            foreach ($stale as $name) {
                $this->line('    <fg=gray>→</> '.$name);
            }

            if ($this->option('prune')) {
                $kept = array_values(array_intersect((array) $user->folder_access, $existing));

                // Stored as [] rather than null: both mean "no folders", but null reads
                // as "never configured".
                $user->forceFill(['folder_access' => $kept])->save();

                $this->components->info(sprintf('  Trimmed to: %s', $kept === [] ? '(none)' : implode(', ', $kept)));
            }
        }

        return $affected;
    }

    /**
     * Report download history rows whose folder is gone. Never pruned.
     *
     * @param  array<int, string>  $existing
     */
    private function auditDownloadJobs(array $existing): int
    {
        $count = DownloadJob::query()->whereNotIn('folder', $existing)->count();

        if ($count === 0) {
            return 0;
        }

        $this->newLine();

        // Never pruned, even with --prune: these rows record where a file came from,
        // and that stays true after the folder is renamed or removed.
        $this->components->warn(sprintf(
            '%d download history row(s) reference a folder that no longer exists.',
            $count,
        ));

        $this->line('  <fg=gray>Left alone: this is history, and it was true when it happened.</>');

        return $count;
    }
}
