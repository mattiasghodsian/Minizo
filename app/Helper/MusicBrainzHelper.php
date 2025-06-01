<?php

namespace App\Helper;

use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class MusicBrainzHelper
{
    private Client $client;
    private string $baseUrl = 'https://musicbrainz.org/ws/2/';
    private const COVER_ART_URL = 'http://coverartarchive.org/';

    public function __construct()
    {
        $token = config('services.musicbrainz.token');

        if (empty($token)) {
            throw new RuntimeException('MusicBrainz API token is not configured. Please set MUSICBRAINZ_TOKEN in your .env file.');
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => sprintf("Bearer %s", $token),
                'User-Agent'    => 'Minizo ( https://github.com/mattiasghodsian/Minizo )'
            ]
        ]);
    }

    /**
     * Search for releases using artist and track
     * @throws GuzzleException
     */
    public function search(string $artist, string $track): array
    {
        try {
            $response = $this->client->get('release', [
                'query' => [
                    'query' => "track:{$track} AND artist:{$artist}",
                    'fmt' => 'json'
                ]
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw $e;
        }
    }

    /**
     * Get release details by ID
     * @throws GuzzleException
     */
    public function getRelease(string $releaseId): array
    {
        try {
            $response = $this->client->get("release/{$releaseId}", [
                'query' => [
                    'fmt' => 'json',
                    'inc' => implode('+', [
                        'artists',
                        'recordings',
                        'artist-credits',
                        'labels',
                        'genres',
                        'tags',
                        'media',
                        'isrcs',
                        'aliases',
                        'annotation',
                        'url-rels'
                    ])
                ]
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw $e;
        }
    }

    /** 
     * Get cover art for a release
     * @throws GuzzleException
     */
    public function getCoverArt(string $releaseId): array
    {
        try {
            $response = $this->client->get("release/{$releaseId}", [
                'base_uri' => self::COVER_ART_URL 
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                return [];
            }
            throw $e;
        }
    }
}