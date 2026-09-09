<?php

namespace App\Services\Tidal;

use App\Enums\ReleaseType;
use App\Exceptions\TidalException;
use App\Support\TidalArtist;
use App\Support\TidalRelease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TidalCatalogue
{
    /** Searches Tidal and maps artists and releases out of the response. */
    public function __construct(
        private TidalClient $client,
        private TidalResourceMapper $mapper,
    ) {}

    /** Whether Tidal credentials are set. */
    public function configured(): bool
    {
        return $this->client->configured();
    }

    /**
     * Artist search.
     *
     * @return array<int, TidalArtist>
     *
     * @throws TidalException
     */
    public function searchArtists(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $cacheKey = 'minizo:tidal:search:'.sha1(mb_strtolower($query).'|'.config('services.tidal.country'));

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return array_map(fn (array $row): TidalArtist => TidalArtist::fromArray($row), $cached);
        }

        // Tidal moved this off `searchResults/{query}`: the query used to BE the id, and now
        // the id is opaque and the query is a filter. The old path answers 400
        // INVALID_RESOURCE_ID for everything. Nested array, not the literal 'filter[query]',
        // so Guzzle encodes it once - and no rawurlencode, it is a value now, not a segment.
        //
        // `artists.profileArt`, not `artists`: an artist resource carries no image
        // attribute, the picture is a related `artworks` resource.
        $result = $this->client->fetch('searchResults', [
            'include' => 'artists.profileArt',
            'filter' => ['query' => $query],
        ]);

        if (! $result->succeeded()) {
            // The query and country are the half the client does not have. Warning, not
            // info: no status here means "nothing there" - an unknown name is a 200.
            Log::warning('Tidal search failed', [
                'query' => $query,
                'country' => config('services.tidal.country'),
                ...$result->context(),
            ]);

            throw TidalException::searchFailed($result->summary());
        }

        $artists = $this->mapper->artists(TidalDocument::from($result->body));

        Cache::put(
            $cacheKey,
            array_map(fn (TidalArtist $artist): array => $artist->toArray(), $artists),
            (int) config('minizo.feed.search_cache_ttl', 3600),
        );

        return $artists;
    }

    /**
     * One artist, fresh from the catalogue.
     *
     * @throws TidalException
     */
    public function artist(string $providerId): ?TidalArtist
    {
        $result = $this->client->fetch(
            'artists/'.rawurlencode($providerId),
            ['include' => 'profileArt'],
        );

        // Still null: callers treat "no such artist" and "could not ask" alike, and the
        // status is already in the line send() wrote.
        if (! $result->succeeded()) {
            return null;
        }

        $document = TidalDocument::from($result->body);

        return $this->mapper->artist($document, $document->data());
    }

    /**
     * One artist's releases, newest first as Tidal returns them.
     *
     * @return array<int, TidalRelease>
     *
     * @throws TidalException
     */
    public function releasesFor(string $artistProviderId): array
    {
        $cacheKey = 'minizo:tidal:releases:'.$artistProviderId.':'.config('services.tidal.country');

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $this->rehydrate($cached);
        }

        $releases = [];
        $path = 'artists/'.rawurlencode($artistProviderId).'/relationships/albums';

        // `albums.coverArt` for the same reason search needs artists.profileArt: an album's
        // artwork is a related resource, not an attribute. Note the relationship is called
        // coverArt here and profileArt on an artist - `include=coverArt` on an artist is
        // rejected outright, so they are not interchangeable.
        $query = ['include' => 'albums.coverArt', 'limit' => (int) config('minizo.feed.import_limit', 50)];

        for ($page = 0; $page < (int) config('minizo.feed.max_pages', 3); $page++) {
            $result = $this->client->fetch($path, $query);

            if (! $result->succeeded()) {
                /*
                 * Failing partway keeps what was collected; the next sync completes it.
                 *
                 * Failing on page 0 collected nothing, and breaking used to fall into
                 * Cache::put - pinning "no releases" for the whole TTL, with last_synced_at
                 * stamped on top. Throwing caches nothing and SyncArtistReleasesJob already
                 * catches it. A 404 is exempt: that genuinely means "no albums".
                 */
                if ($page > 0 || $result->isNotFound()) {
                    break;
                }

                Log::warning('Tidal releases lookup failed', [
                    'artist' => $artistProviderId,
                    ...$result->context(),
                ]);

                throw TidalException::releasesFailed($artistProviderId, $result->summary());
            }

            $document = TidalDocument::from($result->body);

            foreach ($this->mapper->releases($document) as $release) {
                // Keyed on identity, not id: Tidal lists regional pressings as separate
                // albums with the same title, date and duration. First wins. Type is part
                // of the key so a single alongside its album stays two entries.
                $releases[$release->variantKey()] ??= $release;
            }

            $next = $document->nextLink();

            if ($next === null) {
                break;
            }

            // Only the query is reused; feeding the whole URL back would double the base URI.
            $parsed = parse_url($next);
            parse_str($parsed['query'] ?? '', $nextQuery);

            if ($nextQuery === []) {
                break;
            }

            // Keep one level of nesting: `page[cursor]=…` parses to an array, and a
            // scalars-only filter drops the cursor so every request asks for page one.
            $carried = $query;

            foreach ($nextQuery as $key => $value) {
                // parse_str yields either a scalar or a nested array, never anything deeper
                // at the top level, so these two branches are the whole space.
                $carried[(string) $key] = is_scalar($value)
                    ? $value
                    : array_filter($value, 'is_scalar');
            }

            // A key whose entire value was dropped carries no information and would only
            // confuse the query serialiser.
            $carried = array_filter($carried, fn (mixed $value): bool => $value !== []);

            $query = $carried;
        }

        $releases = array_values($releases);

        Cache::put(
            $cacheKey,
            array_map(fn (TidalRelease $r): array => [
                'providerId' => $r->providerId,
                'title' => $r->title,
                'type' => $r->type?->value,
                'releasedOn' => $r->releasedOn?->toDateString(),
                'coverUrl' => $r->coverUrl,
                'link' => $r->link,
            ], $releases),
            (int) config('minizo.feed.releases_cache_ttl', 1800),
        );

        return $releases;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, TidalRelease>
     */
    private function rehydrate(array $rows): array
    {
        return array_map(fn (array $row): TidalRelease => new TidalRelease(
            providerId: (string) ($row['providerId'] ?? ''),
            title: (string) ($row['title'] ?? ''),
            type: ReleaseType::tryFrom((string) ($row['type'] ?? '')),
            releasedOn: filled($row['releasedOn'] ?? null)
                ? CarbonImmutable::parse((string) $row['releasedOn'])
                : null,
            coverUrl: $row['coverUrl'] ?? null,
            link: $row['link'] ?? null,
        ), $rows);
    }
}
