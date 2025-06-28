<?php

namespace App\Services;

use Illuminate\Support\Arr;

class MetaDataService
{
    public const METADATA_FIELDS = [
        'release_id'    => 'releaseID',
        'title'         => 'title',
        'artist'        => 'artist-credit.*.name',
        'album'         => 'title',
        'year'          => 'date',
        'genre'         => 'genres.*.name',
        'label'         => 'label-info.*.label.name',
        'track_number'  => 'media.0.tracks.0.number',
        'total_tracks'  => 'media.0.track-count',
        'length'        => 'media.0.tracks.0.recording.length',
        'isrc'          => 'media.0.tracks.0.recording.isrcs.0',
        'barcode'       => 'barcode',
        'status'        => 'status',
        'format'        => 'media.0.format',
        'country'       => 'country',
        'link'          => 'relations.*.url.resource',
        'cover_art'     => 'cover_art',
        'language'      => 'text-representation.language'
    ];

    protected array $metadata = [];
    
    public function __construct(array $metadata = [])
    {
        $this->metadata = $metadata;
    }

    public function parseData(): array
    {
        return collect(self::METADATA_FIELDS)
            ->mapWithKeys(function ($paths, $field) {
                return [$field => $this->extractValue($paths)];
            })
            ->filter()
            ->all();
    }

    protected function extractValue(mixed $paths): mixed
    {
        // Special handling for artist fields to combine names with joinphrases
        if (is_array($paths) && str_contains($paths[0], 'artist-credit')) {
            $credits = $this->extractNestedValue('artist-credit');
            if (!is_array($credits)) {
                return null;
            }

            return collect($credits)->reduce(function ($carry, $credit) {
                return $carry . 
                    ($credit['name'] ?? '') . 
                    ($credit['joinphrase'] ?? '');
            }, '');
        }

        // Handle other array paths
        if (is_array($paths)) {
            return collect($paths)
                ->map(fn($path) => $this->extractNestedValue($path))
                ->filter()
                ->first();
        }

        return $this->extractNestedValue($paths);
    }

    protected function extractNestedValue(string $path): mixed
    {
        $segments = explode('.', $path);
        $result = [];
        $this->traverseArray($this->metadata, $segments, $result);

        if (str_contains($path, 'artist-credit.*.name')) {
            // Concatenate artist names when found
            return implode('', $result);
        }

        return !empty($result) ? $result[0] : null;
    }

    protected function traverseArray(array $array, array $segments, array &$result, int $depth = 0): void
    {
        $currentSegment = $segments[$depth];

        // Handle wildcard
        if ($currentSegment === '*') {
            foreach ($array as $key => $value) {
                if (is_array($value)) {
                    if ($depth === count($segments) - 2 && $segments[$depth + 1] === 'name') {
                        // For artist names, include joinphrase
                        $result[] = $value['name'] . ($value['joinphrase'] ?? '');
                    } else {
                        $this->traverseArray($value, $segments, $result, $depth + 1);
                    }
                }
            }
            return;
        }

        // Handle specific key
        if (isset($array[$currentSegment])) {
            if ($depth === count($segments) - 1) {
                $result[] = $array[$currentSegment];
                return;
            }

            if (is_array($array[$currentSegment])) {
                $this->traverseArray($array[$currentSegment], $segments, $result, $depth + 1);
            }
        }
    }

    protected function parseArtistName(): ?string
    {
        $credits = $this->extractNestedValue('artist-credit');
        if (!is_array($credits)) {
            return null;
        }

        return collect($credits)->reduce(function ($carry, $credit) {
            return $carry . 
                   ($credit['name'] ?? '') . 
                   ($credit['joinphrase'] ?? '');
        }, '');
    }

    // /**
    //  * Dynamic getters
    //  */
    // public function __call(string $method, array $arguments): mixed
    // {
    //     if (!Str::startsWith($method, 'get')) {
    //         throw new \BadMethodCallException("Method {$method} does not exist.");
    //     }

    //     $field = Str::snake(substr($method, 3));
    //     if (!array_key_exists($field, self::METADATA_FIELDS)) {
    //         throw new \InvalidArgumentException("Field {$field} is not a valid metadata field.");
    //     }

    //     return $this->get($field);
    // }

    /**
     * Get a specific metadata field
     */
    public function get(string $field): mixed
    {
        $path = self::METADATA_FIELDS[$field] ?? null;
        if (!$path) {
            return null;
        }

        $value = Arr::get($this->metadata, $path, null);
        return match($field) {
            'year' => $value ? substr($value, 0, 4) : null,
            'length' => $value ? (int)($value / 1000) : null,
            'artist' => $this->parseArtistName(),
            default => $value,
        };
    }

}