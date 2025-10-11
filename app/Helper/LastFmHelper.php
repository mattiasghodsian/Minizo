<?php

namespace App\Helper;

use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class LastFmHelper
{
    private Client $client;
    private string $baseUrl = 'https://ws.audioscrobbler.com/2.0/';
    private string $apiKey;
    
    public function __construct()
    {
        $this->apiKey = config('services.lastfm.token');

        if (empty($this->apiKey)) {
            throw new RuntimeException('Last.fm API token is not configured. Please set LASTFM_TOKEN in your .env file.');
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'Minizo ( https://github.com/mattiasghodsian/Minizo )'
            ]
        ]);
    }

    /**
     * Search for an artist
     * @throws GuzzleException
     */
    public function searchArtist(string $artist): array
    {
        try {
            $response = $this->client->get('', [
                'query' => [
                    'method' => 'artist.search',
                    'artist' => $artist,
                    'api_key' => $this->apiKey,
                    'format' => 'json'
                ]
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw $e;
        }
    }

    /**
     * Get top tracks for an artist
     * @param string $artist Artist name
     * @param int $page Page number (starting from 1)
     * @param int $limit Number of results per page
     * @throws GuzzleException
     */
    public function getArtistTopTracks(string $artist, int $page = 1, int $limit = 100): array
    {
        try {
            $response = $this->client->get('', [
                'query' => [
                    'method' => 'artist.getTopTracks',
                    'artist' => $artist,
                    'page' => max(1, $page),
                    'limit' => $limit,
                    'api_key' => $this->apiKey,
                    'format' => 'json'
                ]
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw $e;
        }
    }

    /**
     * Get artist image from Last.fm artist page
     * @param string $url Last.fm artist URL
     */
    public function getArtistImage(string $url): ?string
    {
        $cacheKey = 'artist_image_' . md5($url);

        return cache()->remember($cacheKey, now()->addHours(24), function () use ($url) {
            $html = @file_get_contents($url);
            if (!$html) {
                return null;
            }

            $dom = new \DOMDocument();
            @$dom->loadHTML($html);

            $xpath = new \DOMXPath($dom);
            $nodes = $xpath->query('//*[contains(@class, "header-new-background-image")]');

            if ($nodes->length === 0) {
                return null;
            }

            $style = $nodes[0]->getAttribute('style');

            if (preg_match('/background-image:\s*url\([\'"]?(.*?)[\'"]?\)/i', $style, $matches)) {
                $imageUrl = trim($matches[1]);

                if (is_array($imageUrl) || is_object($imageUrl)) {
                    return null;
                }

                return $imageUrl;
            }

            return null;
        });
    }

}