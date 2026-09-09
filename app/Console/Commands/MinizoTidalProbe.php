<?php

namespace App\Console\Commands;

use App\Exceptions\TidalException;
use App\Services\Tidal\TidalClient;
use App\Services\Tidal\TidalDocument;
use App\Services\Tidal\TidalResourceMapper;
use App\Services\Tidal\TidalResult;
use Illuminate\Console\Command;

class MinizoTidalProbe extends Command
{
    protected $signature = 'minizo:tidal:probe
        {query=ANITTA : an artist name to search for}
        {--artist= : a Tidal artist id, to probe that artist\'s releases instead}
        {--fresh : drop the cached token first, so this exercises a real token fetch}
        {--save : write the raw response to tests/Fixtures/tidal/}';

    protected $description = 'Fetch a real Tidal response and show how Minizo maps it';

    /** Call one Tidal endpoint and print the raw document beside the mapped result. */
    public function handle(TidalClient $client, TidalResourceMapper $mapper): int
    {
        if (! $client->configured()) {
            $this->components->error('Tidal is not configured.');
            $this->line('  Add TIDAL_CLIENT_ID and TIDAL_CLIENT_SECRET to .env.');
            $this->line('  Register an application at https://developer.tidal.com');

            return self::FAILURE;
        }

        $this->reportConfig();

        if ($this->option('fresh')) {
            $client->forgetToken();
        }

        $minted = ! $client->hasCachedToken();

        $artistId = $this->option('artist');

        [$label, $path, $query] = $artistId !== null
            ? ["releases for artist {$artistId}", 'artists/'.rawurlencode((string) $artistId).'/relationships/albums', ['include' => 'albums.coverArt', 'limit' => 10]]
            // The query is a `filter[query]` parameter, not a path segment - Tidal moved the
            // searchResults resource to an opaque id. This MUST stay identical to
            // TidalCatalogue::searchArtists(), path included: a probe that asks a different
            // way captures a fixture production never requested, and cannot reproduce an
            // outage caused by the request shape itself.
            : ['artist search for "'.$this->argument('query').'"', 'searchResults', ['include' => 'artists.profileArt', 'filter' => ['query' => (string) $this->argument('query')]]];

        $this->components->info('Probing '.$label);
        $this->line('  token '.($minted ? 'will be minted' : 'reused from cache'));

        try {
            $result = $client->fetch($path, $query);
        } catch (TidalException $e) {
            // detailedMessage(), not getMessage(): the operator half is the whole point of
            // running the probe, and bearerRejected() carries its diagnosis there.
            $this->components->error($e->detailedMessage());

            return self::FAILURE;
        }

        if (! $result->succeeded()) {
            $this->components->error('Tidal returned no usable response.');
            $this->line('  <fg=red>'.$result->summary().'</>');
            $this->hint($result);

            return self::FAILURE;
        }

        $body = $result->body;
        $document = TidalDocument::from($body);

        // ---- what the document actually contains
        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>top-level keys</>', implode(', ', array_keys($body)));
        $this->components->twoColumnDetail('primary data type', (string) ($document->data()['type'] ?? '(none)'));
        $this->components->twoColumnDetail('included resources', (string) count($body['included'] ?? []));
        $this->components->twoColumnDetail('links.next', $document->nextLink() ?? '(none)');

        // ---- the attribute keys per included type, which is the thing being verified
        $byType = [];

        foreach ($body['included'] ?? [] as $resource) {
            $type = $resource['type'] ?? '?';
            $byType[$type] ??= [];
            $byType[$type] = array_unique([...$byType[$type], ...array_keys($resource['attributes'] ?? [])]);
        }

        foreach ($byType as $type => $keys) {
            $this->newLine();
            $this->components->info("attributes on `{$type}` resources");
            $this->line('  '.implode(', ', $keys));
        }

        // ---- and what the mapper made of them
        $this->newLine();

        if ($artistId !== null) {
            $releases = $mapper->releases($document);
            $mapped = count($releases);
            $this->components->info('TidalResourceMapper extracted '.$mapped.' release(s)');

            foreach (array_slice($releases, 0, 8) as $release) {
                $this->components->twoColumnDetail(
                    $release->title,
                    sprintf('%s · %s · %s',
                        $release->type?->label() ?? '<fg=red>no type</>',
                        $release->releasedOn?->toDateString() ?? '<fg=red>no date</>',
                        $release->coverUrl !== null ? 'cover' : '<fg=red>no cover</>',
                    ),
                );
            }
        } else {
            $artists = $mapper->artists($document);
            $mapped = count($artists);
            $this->components->info('TidalResourceMapper extracted '.$mapped.' artist(s)');

            foreach (array_slice($artists, 0, 8) as $artist) {
                $this->components->twoColumnDetail(
                    $artist->name.' <fg=gray>('.$artist->providerId.')</>',
                    $artist->imageUrl !== null ? 'image' : '<fg=red>no image</>',
                );
            }
        }

        // Only when the document HAD something to include. A query that genuinely matches
        // nothing answers 200 with an empty list, and warning there sends the reader after
        // an include parameter that is fine.
        if (count($byType) === 0 && $mapped > 0) {
            $this->newLine();
            $this->components->warn('No included resources — the ?include= parameter may be wrong for this endpoint.');
        }

        if ($this->option('save')) {
            $this->save($artistId !== null ? 'artist-releases' : 'artist-search', $body);
        }

        return self::SUCCESS;
    }

    /** The resolved Tidal config, so a stale override shows up in one line. */
    private function reportConfig(): void
    {
        $rows = [
            'base_uri' => config('services.tidal.base_uri'),
            'token_uri' => config('services.tidal.token_uri'),
            'country' => config('services.tidal.country'),
            'timeout' => config('services.tidal.timeout'),
            'retries' => config('services.tidal.retries'),
            'client_id' => config('services.tidal.client_id'),
            // Never print the secret. Whether one is set is the useful fact.
            'client_secret' => filled(config('services.tidal.client_secret')) ? '******** (set)' : null,
        ];

        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $this->line(sprintf('    %-14s %s', $label, $value));
        }
    }

    /** Turn the status into something actionable. */
    private function hint(TidalResult $result): void
    {
        $hint = match (true) {
            $result->status === null => 'The request never reached Tidal. Check DNS and outbound HTTPS; Guzzle honours HTTP_PROXY from an environment PHP-FPM does not share with your shell.',
            $result->status === 400 => 'The request shape is wrong. Compare it against tests/Fixtures/tidal/README.md — this is what an API migration looks like.',
            $result->status === 401,
            $result->status === 403 => 'Authenticated but not allowed. Check the app at developer.tidal.com has catalogue access and is approved, and that TIDAL_COUNTRY is a market you are licensed for.',
            $result->status === 404 => 'Wrong path. Compare TIDAL_BASE_URI against the default in config/services.php; it must end in /v2/.',
            $result->status === 429 => 'Rate limited. Retry-After is honoured, so this should clear on its own.',
            $result->status >= 500 => 'Tidal\'s side. Already retried '.config('services.tidal.retries').' times.',
            default => null,
        };

        if ($hint !== null) {
            $this->line('  <fg=cyan>Likely cause:</> '.$hint);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function save(string $name, array $body): void
    {
        $dir = base_path('tests/Fixtures/tidal');

        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $path = $dir.'/'.$name.'.json';

        file_put_contents($path, json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->components->info('Saved to tests/Fixtures/tidal/'.$name.'.json');
        $this->line('  TidalMapperTest picks it up automatically in place of the authored fixture.');
    }
}
