<?php

namespace App\Support;

final readonly class TidalArtist
{
    /** One artist as Tidal describes them. */
    public function __construct(
        public string $providerId,
        public string $name,
        public ?string $imageUrl = null,
        public ?int $popularity = null,
        public ?string $link = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'providerId' => $this->providerId,
            'name' => $this->name,
            'imageUrl' => $this->imageUrl,
            'popularity' => $this->popularity,
            'link' => $this->link,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            providerId: (string) ($row['providerId'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            imageUrl: self::safeImageUrl($row['imageUrl'] ?? null),
            popularity: isset($row['popularity']) && is_numeric($row['popularity']) ? (int) $row['popularity'] : null,
            link: isset($row['link']) && is_string($row['link']) ? $row['link'] : null,
        );
    }

    /** Artist images are rendered in an <img>, and the URL arrives from a third party and then crosses the wire in a Livewire payload. Restricting it to https on Tidal's own CDN keeps a tampered payload from pointing the browser somewhere else. */
    public static function safeImageUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (['tidal.com', 'tidalhifi.com'] as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return $url;
            }
        }

        return null;
    }
}
