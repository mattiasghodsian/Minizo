<?php

namespace App\Helper;

use RuntimeException;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
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
            // Only add brackets if not already present
            $artistQuery = (str_starts_with($artist, '[') && str_ends_with($artist, ']'))
                ? $artist
                : "[{$artist}]";

            $response = $this->client->get('release', [
                'query' => [
                    'query' => "track:{$track} AND artist:{$artistQuery}",
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
    /**
     * Get artist details by MBID
     * @throws GuzzleException
     */
    public function getArtistInfo(string $mbid): array 
    {
        try {
            $response = $this->client->get("artist/{$mbid}", [
                'query' => [
                    'inc' => 'url-rels',
                    'fmt' => 'json'
                ]
            ]);

            return json_decode($response->getBody()->getContents(), true);

        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Get artist links by MBID
     * @throws GuzzleException
     */
    public function getArtistLinks(string $mbid): array
    {
        $data       = $this->getArtistInfo($mbid);
        $relations  = [];

        if (!empty($data) && Arr::get($data, 'relations', []) !== []) {
            return array_map(function ($relation) {
                return [
                    'type' => Arr::get($relation, 'type', ''),
                    'url'  => Arr::get($relation, 'url.resource', '')
                ];
            }, Arr::get($data, 'relations'));
        }

        return $relations;
    }

    /**
     * Extract track listing from release data
     * @param array $releaseData Full release data from getRelease()
     * @param string $searchTitle Original search title for matching
     * @return array Track list with match indicators
     */
    public function extractTrackListing(array $releaseData, string $searchTitle = ''): array
    {
        $tracks = [];
        $mediaList = Arr::get($releaseData, 'media', []);

        foreach ($mediaList as $mediaIndex => $media) {
            $mediaFormat = Arr::get($media, 'format', 'Unknown');
            $trackList = Arr::get($media, 'tracks', []);

            foreach ($trackList as $trackIndex => $track) {
                $trackTitle = Arr::get($track, 'title', '');
                $recordingTitle = Arr::get($track, 'recording.title', $trackTitle);
                $displayTitle = $trackTitle ?: $recordingTitle;

                // Calculate match score for highlighting
                $matchScore = 0;
                if (!empty($searchTitle)) {
                    similar_text(
                        strtolower($searchTitle),
                        strtolower($displayTitle),
                        $matchScore
                    );
                }

                $tracks[] = [
                    'position'      => Arr::get($track, 'number', ''),
                    'media_position' => $mediaIndex,
                    'track_index'   => $trackIndex,
                    'title'         => $displayTitle,
                    'length'        => Arr::get($track, 'length', 0),
                    'length_formatted' => $this->formatDuration(Arr::get($track, 'length', 0)),
                    'recording_id'  => Arr::get($track, 'recording.id', ''),
                    'match_score'   => round($matchScore, 2),
                    'is_best_match' => false, // Will be set after all tracks processed
                    'media_format'  => $mediaFormat,
                ];
            }
        }

        // Mark the best match
        if (!empty($tracks) && !empty($searchTitle)) {
            $bestMatch = collect($tracks)->sortByDesc('match_score')->first();
            $bestMatchIndex = collect($tracks)->search(fn($t) => $t['match_score'] === $bestMatch['match_score']);
            $tracks[$bestMatchIndex]['is_best_match'] = true;
        }

        return $tracks;
    }

    /**
     * Format duration from milliseconds to MM:SS
     */
    private function formatDuration(int $milliseconds): string
    {
        if ($milliseconds === 0) return '0:00';
        $seconds = round($milliseconds / 1000);
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }
}