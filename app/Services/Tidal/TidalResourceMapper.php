<?php

namespace App\Services\Tidal;

use App\Enums\ReleaseType;
use App\Support\Scalar;
use App\Support\TidalArtist;
use App\Support\TidalRelease;
use Carbon\CarbonImmutable;
use Throwable;

class TidalResourceMapper
{
    /**
     * Artists out of a search document, in the order the API ranked them.
     *
     * @return array<int, TidalArtist>
     */
    public function artists(TidalDocument $document): array
    {
        return $this->map(
            $document,
            'artists',
            fn (array $resource): ?TidalArtist => $this->artist($document, $resource),
        );
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    public function artist(TidalDocument $document, array $resource): ?TidalArtist
    {
        $id = $resource['id'] ?? null;

        // `name`, with no `title` fallback. Verified: an artist resource carries name and an
        // album carries title, and accepting either here means an album document handed to
        // artists() maps into artists instead of returning nothing.
        $name = $document->attribute($resource, 'name');

        // An artist with no id cannot be followed and one with no name cannot be shown, so
        // neither is worth returning a half-built object for.
        if (! is_scalar($id) || ! is_string($name) || trim($name) === '') {
            return null;
        }

        return new TidalArtist(
            providerId: (string) $id,
            name: trim($name),
            imageUrl: TidalArtist::safeImageUrl($this->imageUrl($document, $resource, 'profileArt')),
            popularity: $this->popularity($document->attribute($resource, 'popularity')),
            link: $this->externalLink($document, $resource) ?? 'https://tidal.com/browse/artist/'.$id,
        );
    }

    /**
     * Releases out of an artist-albums document.
     *
     * @return array<int, TidalRelease>
     */
    public function releases(TidalDocument $document): array
    {
        return $this->map(
            $document,
            'albums',
            fn (array $resource): ?TidalRelease => $this->release($document, $resource),
        );
    }

    /**
     * Every resource of a type in the document, IN THE ORDER THE API GAVE THEM.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): ?T  $map
     * @return array<int, T>
     */
    private function map(TidalDocument $document, string $type, callable $map): array
    {
        $identifiers = array_filter(
            $document->collection(),
            fn (array $resource): bool => ($resource['type'] ?? null) === $type,
        );

        $resources = [];

        foreach ($identifiers as $identifier) {
            $resolved = $document->resolve($identifier);

            if ($resolved !== null) {
                $resources[] = $resolved;

                continue;
            }

            // Nothing in `included` for it: either `data` holds the whole resource, or
            // ?include= was omitted. Checking for attributes tells the two apart.
            if (($identifier['attributes'] ?? null) !== null) {
                $resources[] = $identifier;
            }
        }

        if ($resources === []) {
            $resources = $document->relatedTo($type, $type);
        }

        // Last resort, and it loses the ranking. Only reached when a document carries
        // the resources as includes with nothing pointing at them.
        if ($resources === []) {
            $resources = $document->included($type);
        }

        return array_values(array_filter(array_map($map, $resources)));
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    public function release(TidalDocument $document, array $resource): ?TidalRelease
    {
        $id = $resource['id'] ?? null;
        $title = $document->attribute($resource, 'title');

        if (! is_scalar($id) || ! is_string($title) || trim($title) === '') {
            return null;
        }

        return new TidalRelease(
            providerId: (string) $id,
            title: trim($title),
            // An album carries BOTH `type` and `albumType`, and they agree - verified against a
            // real response, where every entry had the same token in each. `type` is preferred
            // as the more general of the two.
            type: ReleaseType::fromTidal(Scalar::stringOrNull($this->candidates($document, $resource, ['type', 'albumType']))),
            releasedOn: $this->date($document->attribute($resource, 'releaseDate')),
            coverUrl: TidalArtist::safeImageUrl($this->imageUrl($document, $resource, 'coverArt')),
            link: $this->externalLink($document, $resource) ?? 'https://tidal.com/browse/album/'.$id,
        );
    }

    // ------------------------------------------------------------------ internals

    /**
     * The first of several candidate attribute keys that has a value.
     *
     * @param  array<string, mixed>  $resource
     * @param  array<int, string>  $keys
     */
    private function candidates(TidalDocument $document, array $resource, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $document->attribute($resource, $key);

            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The resource's picture, via its artwork relationship.
     *
     * @param  array<string, mixed>  $resource
     * @param  string  $relationship  profileArt for an artist, coverArt for an album
     */
    private function imageUrl(TidalDocument $document, array $resource, string $relationship): ?string
    {
        foreach ($document->identifiers($resource, $relationship) as $identifier) {
            $artwork = $document->resolve($identifier);

            if ($artwork === null) {
                continue;
            }

            $best = $this->closestTo320($document->attribute($artwork, 'files'));

            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    /** The size nearest 320px from an artwork's file list. */
    private function closestTo320(mixed $files): ?string
    {
        if (! is_array($files)) {
            return null;
        }

        $best = null;
        $bestWidth = null;

        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($href = $file['href'] ?? null)) {
                continue;
            }

            $width = (int) ($file['meta']['width'] ?? 0);
            $height = (int) ($file['meta']['height'] ?? 0);

            // Square crops only. The design's tiles are square, and a 1280x720 landscape
            // crop stretched into one puts the subject's face off-centre.
            if ($width !== $height) {
                continue;
            }

            if ($bestWidth === null || abs($width - 320) < abs($bestWidth - 320)) {
                $best = $href;
                $bestWidth = $width;
            }
        }

        return $best;
    }

    /**
     * The resource's own tidal.com page.
     *
     * @param  array<string, mixed>  $resource
     */
    private function externalLink(TidalDocument $document, array $resource): ?string
    {
        $links = $document->attribute($resource, 'externalLinks');

        if (! is_array($links)) {
            return null;
        }

        foreach ($links as $link) {
            if (! is_array($link) || ! is_string($href = $link['href'] ?? null)) {
                continue;
            }

            if (($link['meta']['type'] ?? null) === 'TIDAL_SHARING' && str_starts_with($href, 'https://')) {
                return $href;
            }
        }

        // No Tidal entry: the caller builds a browse URL from the id, which always works.
        return null;
    }

    /** Tidal dates are ISO-8601 dates, but some catalogue entries carry a full timestamp. */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            // A malformed date is not worth failing an import over; the release is still
            // worth having, just unordered.
            return null;
        }
    }

    /** Popularity as a 0-100 integer. */
    private function popularity(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        if ($value < 0) {
            return null;
        }

        return (int) round($value <= 1 ? $value * 100 : min($value, 100));
    }
}
