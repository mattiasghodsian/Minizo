<?php

namespace App\Console\Commands;

use App\Exceptions\TidalException;
use App\Services\Tidal\TidalClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class MinizoDoctor extends Command
{
    protected $signature = 'minizo:doctor
        {--offline : skip the live integration checks}';

    protected $description = 'Check the binaries, disks and integrations Minizo depends on';

    /**
     * External binaries, with the argument that makes each print its version.
     *
     * @var array<string, array{args: array<int, string>, purpose: string}>
     */
    private const BINARIES = [
        'yt-dlp' => [
            'args' => ['--version'],
            'purpose' => 'Downloading audio',
        ],
        'ffmpeg' => [
            'args' => ['-version'],
            'purpose' => 'Extracting/converting audio to FLAC',
        ],
        'metaflac' => [
            'args' => ['--version'],
            // Both halves of the tag write, not just the artwork: it is also how
            // Vorbis comments are written, because it is the only route that takes
            // UTF-8 without transcoding it a second time.
            'purpose' => 'Writing FLAC tags and cover art',
        ],
    ];

    /** Check the external tools and the library disk, and report the integrations. */
    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Minizo environment check');

        $failures = 0;

        $failures += $this->checkBinaries();
        $failures += $this->checkLibraryDisk();
        $failures += $this->checkIntegrations();

        $this->newLine();

        if ($failures > 0) {
            $this->components->error("{$failures} required check(s) failed.");

            return self::FAILURE;
        }

        $this->components->info('All required checks passed.');

        return self::SUCCESS;
    }

    /** Report which external binaries are present and which features need them. */
    private function checkBinaries(): int
    {
        $finder = new ExecutableFinder;
        $failures = 0;

        foreach (self::BINARIES as $binary => $spec) {
            $path = $finder->find($binary);

            if ($path === null) {
                $this->line(sprintf(
                    '  <fg=red>MISSING</> %-10s %s (not on PATH)',
                    $binary,
                    $spec['purpose'],
                ));

                $failures++;

                continue;
            }

            $version = $this->versionOf($path, $spec['args']);

            $this->line(sprintf(
                '  <fg=green>ok</>      %-10s %s',
                $binary,
                $version ?? 'installed (version unavailable)',
            ));
        }

        return $failures;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function versionOf(string $path, array $args): ?string
    {
        // A hard timeout matters here: the point of this command is to diagnose a
        // broken environment, and a hanging binary is one of the things it may find.
        $process = new Process([$path, ...$args]);
        $process->setTimeout(10);

        try {
            $process->run();
        } catch (ProcessTimedOutException|ProcessFailedException) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $firstLine = strtok(trim($process->getOutput()), "\n");

        return $firstLine === false ? null : trim($firstLine);
    }

    /** Report whether the music disk is mounted, readable and writable. */
    private function checkLibraryDisk(): int
    {
        $disk = config('minizo.library.disk');
        $root = config("filesystems.disks.{$disk}.root");

        $this->newLine();
        $this->line("  Library disk <fg=gray>[{$disk}]</> {$root}");

        try {
            $folders = Storage::disk($disk)->directories('/');
        } catch (Throwable $e) {
            $this->line('  <fg=red>MISSING</> library is not readable: '.$e->getMessage());

            return 1;
        }

        if (! is_writable((string) $root)) {
            // Not fatal - a read-only library is a legitimate way to run Minizo -
            // but it silently disables every write action, so say so.
            $this->line('  <fg=yellow>warn</>    library is not writable; folder and tag edits will fail');
        }

        $this->line(sprintf(
            '  <fg=green>ok</>      %d folder(s) visible',
            count($folders),
        ));

        return 0;
    }

    /** Report the integrations, and prove Tidal actually answers. */
    private function checkIntegrations(): int
    {
        $this->newLine();
        $this->line('  Integrations');

        $this->reportToken('MusicBrainz', 'services.musicbrainz.token', 'metadata lookup');

        // Both halves, not just the secret: an id without a secret cannot obtain a token,
        // and checking one meant an install missing the other reported ok.
        $id = filled(config('services.tidal.client_id'));
        $secret = filled(config('services.tidal.client_secret'));

        if (! $id || ! $secret) {
            $missing = match (true) {
                ! $id && ! $secret => 'no client id or secret',
                ! $id => 'no client id',
                default => 'no client secret',
            };

            // Unconfigured is a supported state, not a failure: the feature degrades and
            // the rest of the app keeps working.
            $this->line(sprintf('  <fg=yellow>warn</>    %-12s %s — the artist feed is unavailable', 'TIDAL', $missing));

            return 0;
        }

        $this->line(sprintf('  <fg=green>ok</>      %-12s client id and secret present', 'TIDAL'));

        if ($this->option('offline')) {
            return 0;
        }

        return $this->checkTidalReachable();
    }

    /** Ask Tidal the same thing the Feed asks. A check of some other path proves nothing. */
    private function checkTidalReachable(): int
    {
        $client = app(TidalClient::class);
        $minted = ! $client->hasCachedToken();
        $startedAt = microtime(true);

        try {
            $result = $client->fetch('searchResults', [
                'include' => 'artists.profileArt',
                'filter' => ['query' => 'ANITTA'],
            ]);
        } catch (TidalException $e) {
            $this->line(sprintf('  <fg=red>FAIL</>    %-12s %s', 'TIDAL', $e->detailedMessage()));
            $this->line('          Run `php artisan minizo:tidal:probe ANITTA --fresh` for the full diagnosis.');

            return 1;
        }

        $elapsed = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $result->succeeded()) {
            $this->line(sprintf('  <fg=red>FAIL</>    %-12s catalogue refused the request — %s', 'TIDAL', $result->summary()));
            $this->line('          Run `php artisan minizo:tidal:probe ANITTA --fresh` for the full diagnosis.');

            return 1;
        }

        $this->line(sprintf(
            '  <fg=green>ok</>      %-12s catalogue answered in %dms (token %s)',
            'TIDAL',
            $elapsed,
            $minted ? 'minted' : 'reused',
        ));

        return 0;
    }

    /** A token-or-not line for an integration with nothing to call. */
    private function reportToken(string $name, string $key, string $feature): void
    {
        $this->line(filled(config($key))
            ? sprintf('  <fg=green>ok</>      %-12s configured', $name)
            : sprintf('  <fg=yellow>warn</>    %-12s no token — %s is unavailable', $name, $feature),
        );
    }
}
