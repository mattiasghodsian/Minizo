<?php

namespace App\Console\Commands;

use App\Exceptions\TidalException;
use App\Services\Tidal\TidalClient;
use App\Services\Tidal\TidalDocument;
use App\Services\Tidal\TidalResourceMapper;
use Illuminate\Console\Command;

class MinizoTidalProbe extends Command
{
    protected $signature = 'minizo:tidal:probe
        {query=ANITTA : an artist name to search for}
        {--artist= : a Tidal artist id, to probe that artist\'s releases instead}
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

        $artistId = $this->option('artist');

        [$label, $path, $query] = $artistId !== null
            ? ["releases for artist {$artistId}", 'artists/'.rawurlencode((string) $artistId).'/relationships/albums', ['include' => 'albums.coverArt', 'limit' => 10]]
            : ['artist search for "'.$this->argument('query').'"', 'searchResults/'.rawurlencode((string) $this->argument('query')), ['include' => 'artists.profileArt']];

        $this->components->info('Probing '.$label);

        try {
            $body = $client->get($path, $query);
        } catch (TidalException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($body === null) {
            $this->components->error('Tidal returned no usable response. Check storage/logs for the status and detail.');

            return self::FAILURE;
        }

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
            $this->components->info('TidalResourceMapper extracted '.count($releases).' release(s)');

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
            $this->components->info('TidalResourceMapper extracted '.count($artists).' artist(s)');

            foreach (array_slice($artists, 0, 8) as $artist) {
                $this->components->twoColumnDetail(
                    $artist->name.' <fg=gray>('.$artist->providerId.')</>',
                    $artist->imageUrl !== null ? 'image' : '<fg=red>no image</>',
                );
            }
        }

        if (count($byType) === 0) {
            $this->newLine();
            $this->components->warn('No included resources — the ?include= parameter may be wrong for this endpoint.');
        }

        if ($this->option('save')) {
            $this->save($artistId !== null ? 'artist-releases' : 'artist-search', $body);
        }

        return self::SUCCESS;
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
