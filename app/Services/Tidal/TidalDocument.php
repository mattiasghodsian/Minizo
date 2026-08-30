<?php

namespace App\Services\Tidal;

final class TidalDocument
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, array<string, mixed>>  $included  Keyed "type:id".
     */
    private function __construct(
        private readonly array $body,
        private readonly array $included,
    ) {}

    /**
     * @param  array<string, mixed>|null  $body
     */
    public static function from(?array $body): self
    {
        $body ??= [];

        $index = [];

        foreach ($body['included'] ?? [] as $resource) {
            if (! is_array($resource)) {
                continue;
            }

            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;

            if (! is_string($type) || ! is_scalar($id)) {
                continue;
            }

            // Keyed on BOTH parts. Ids are only unique within a type in JSON:API, so an
            // album and an artist can legitimately share the id "12345".
            $index[$type.':'.$id] = $resource;
        }

        return new self($body, $index);
    }

    /**
     * The primary resource, when `data` is a single object.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = $this->body['data'] ?? [];

        // `data` is an object for a single resource and a list for a collection. Return
        // the first element for a list so callers asking for "the" resource are not
        // surprised by a numerically-indexed array.
        if (array_is_list($data)) {
            return is_array($data[0] ?? null) ? $data[0] : [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * The primary resources, when `data` is a collection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collection(): array
    {
        $data = $this->body['data'] ?? [];

        if (! is_array($data)) {
            return [];
        }

        // Normalised so a single-resource document and a collection can be consumed the
        // same way - endpoints differ on this and the difference is never interesting.
        return array_is_list($data)
            ? array_values(array_filter($data, 'is_array'))
            : [$data];
    }

    /**
     * Every resource of a given type from `included`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function relatedTo(string $relationship, string $type): array
    {
        $identifiers = $this->identifiers($this->data(), $relationship);

        $resolved = [];

        foreach ($identifiers as $identifier) {
            $resource = $this->resolve($identifier);

            // Skipped rather than defaulted when `included` does not carry it: that
            // happens whenever the caller forgot ?include=, and a half-empty artist card
            // is worse than one fewer result.
            if ($resource !== null && ($resource['type'] ?? null) === $type) {
                $resolved[] = $resource;
            }
        }

        return $resolved;
    }

    /**
     * Resource identifiers for one relationship of a resource.
     *
     * @param  array<string, mixed>  $resource
     * @return array<int, array<string, mixed>>
     */
    public function identifiers(array $resource, string $relationship): array
    {
        $data = $resource['relationships'][$relationship]['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        // to-one relationships carry an object, to-many carry a list. Both are legal
        // JSON:API and Tidal uses both.
        return array_is_list($data)
            ? array_values(array_filter($data, 'is_array'))
            : [$data];
    }

    /**
     * Look one identifier up in `included`.
     *
     * @param  array<string, mixed>  $identifier
     * @return array<string, mixed>|null
     */
    public function resolve(array $identifier): ?array
    {
        $type = $identifier['type'] ?? null;
        $id = $identifier['id'] ?? null;

        if (! is_string($type) || ! is_scalar($id)) {
            return null;
        }

        return $this->included[$type.':'.$id] ?? null;
    }

    /**
     * Every included resource of a type, regardless of what points at it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function included(string $type): array
    {
        return array_values(array_filter(
            $this->included,
            fn (array $resource): bool => ($resource['type'] ?? null) === $type,
        ));
    }

    /**
     * An attribute off a resource, with a dotted path.
     *
     * @param  array<string, mixed>  $resource
     */
    public function attribute(array $resource, string $key, mixed $default = null): mixed
    {
        return data_get($resource['attributes'] ?? [], $key, $default);
    }

    /** The `next` pagination link, if the document has one. */
    public function nextLink(): ?string
    {
        $next = $this->body['links']['next'] ?? null;

        return is_string($next) && $next !== '' ? $next : null;
    }

    /** Whether the document carries no resources at all. */
    public function isEmpty(): bool
    {
        return $this->collection() === [] && $this->included === [];
    }

    /**
     * The raw body, for the probe command that captures fixtures.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->body;
    }
}
