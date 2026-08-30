<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MusicBrainz
    |--------------------------------------------------------------------------
    |
    | Used to look up release and recording metadata when tagging a file, plus
    | cover art from the Cover Art Archive (which needs no credentials).
    |
    | The User-Agent is NOT optional: MusicBrainz answers requests without a
    | meaningful one with HTTP 503. It must identify the application and give a
    | contact URL. Rate limit is one request per second.
    |
    | A missing token degrades the metadata feature; it must never break plain
    | library browsing. See App\Services\MusicBrainz\MusicBrainzClient.
    |
    */

    'musicbrainz' => [
        'token' => env('MUSICBRAINZ_TOKEN'),
        'base_uri' => env('MUSICBRAINZ_BASE_URI', 'https://musicbrainz.org/ws/2/'),
        'cover_art_uri' => env('COVER_ART_ARCHIVE_URI', 'https://coverartarchive.org/'),
        'user_agent' => env('MUSICBRAINZ_USER_AGENT', 'Minizo/2.0 ( https://github.com/mattiasghodsian/Minizo )'),
    ],

    /*
    |--------------------------------------------------------------------------
    | TIDAL
    |--------------------------------------------------------------------------
    |
    | Powers the Feed: artist search, and each followed artist's new releases.
    | This replaces Last.fm, which required scraping artist images out of HTML
    | because they removed them from their API.
    |
    | Auth is OAuth 2.1 client credentials — register an app at
    | developer.tidal.com to get a client id and secret. The token is cached, not
    | requested per call.
    |
    | `country` is not optional: every catalogue endpoint requires countryCode,
    | and it also decides which releases are visible, since availability is
    | licensed per territory.
    |
    | With no credentials the Feed reports itself unavailable and nothing else in
    | the app is affected.
    |
    */

    'tidal' => [
        'client_id' => env('TIDAL_CLIENT_ID'),
        'client_secret' => env('TIDAL_CLIENT_SECRET'),
        'token_uri' => env('TIDAL_TOKEN_URI', 'https://auth.tidal.com/v1/oauth2/token'),
        'base_uri' => env('TIDAL_BASE_URI', 'https://openapi.tidal.com/v2/'),
        'country' => env('TIDAL_COUNTRY', 'US'),
        'timeout' => (int) env('TIDAL_TIMEOUT', 15),
    ],

];
