<?php

use App\Enums\AudioFormat;

return [

    /*
    |--------------------------------------------------------------------------
    | Library
    |--------------------------------------------------------------------------
    |
    | The music library is a directory tree exactly one level deep, on the disk
    | named below. The filesystem is the source of truth for content; the
    | database only ever stores facts *about* content (who may see it, what is
    | shared, what is queued). There is no `tracks` table.
    |
    | Because folder listings are read on nearly every request, they are cached.
    | The legacy app rescanned the whole tree on every page load, which was its
    | single largest source of latency.
    |
    */

    'library' => [
        'disk' => 'music',

        /*
         * Extensions the library will LIST.
         *
         * Deliberately wider than AudioFormat, and the distinction matters:
         *
         *   AudioFormat        what Minizo will download and write tags to (FLAC)
         *   library.extensions what Minizo will show you that you already own
         *
         * Collapsing the two would mean an existing mp3 collection renders as an
         * empty library, which is worse than useless — it looks like data loss.
         * Non-taggable files are listed, and the metadata action is simply absent
         * on them (see LibraryFile::isTaggable()).
         */
        'extensions' => ['flac', 'mp3', 'm4a', 'opus', 'ogg', 'wav', 'aac', 'wma'],

        // Seconds to cache folder and file listings. Invalidated explicitly on
        // every write, so this is only a backstop against out-of-band changes
        // made directly on the host.
        'cache_ttl' => (int) env('MINIZO_LIBRARY_CACHE_TTL', 300),

        // Folder names that may never be created or renamed to.
        'reserved_folder_names' => ['.', '..'],

        /*
         * How long a file's parsed tags and stream facts are cached.
         *
         * A week, and safely so: the cache key contains the file's path, mtime and size,
         * so an entry can only go stale if the file changes — and then the key changes
         * with it. Long because parsing means reading a 35 MB FLAC, and the cover
         * endpoint behind every row in a listing consults this.
         */
        'tag_cache_ttl' => (int) env('MINIZO_TAG_CACHE_TTL', 604800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Downloads
    |--------------------------------------------------------------------------
    |
    | yt-dlp is invoked through norkunas/youtube-dl-php, which discovers the
    | binary on PATH. The Docker images ship it along with ffmpeg.
    |
    */

    'downloads' => [
        'format' => AudioFormat::default(),

        // FLAC compression level, passed through to the ExtractAudio
        // postprocessor. yt-dlp's --audio-quality is a no-op for lossless
        // formats, so this is the real quality knob. 0 is fastest, 12 smallest;
        // 8 is ffmpeg's default and 12 costs noticeable CPU for ~1% size.
        'flac_compression_level' => (int) env('MINIZO_FLAC_COMPRESSION_LEVEL', 8),

        // Dedicated queue. With only two workers, long downloads on the default
        // queue would starve everything else.
        'queue' => env('MINIZO_DOWNLOAD_QUEUE', 'downloads'),

        'tries' => (int) env('MINIZO_DOWNLOAD_TRIES', 3),

        // Passed straight through to yt-dlp. These are its own retry counters,
        // which recover from a dropped fragment without the job having to be
        // retried from scratch — much cheaper than burning one of `tries`.
        'retries' => (string) env('MINIZO_DOWNLOAD_RETRIES', '3'),
        'fragment_retries' => (string) env('MINIZO_DOWNLOAD_FRAGMENT_RETRIES', '10'),
        'concurrent_fragments' => (int) env('MINIZO_DOWNLOAD_CONCURRENT_FRAGMENTS', 4),
        'socket_timeout' => (int) env('MINIZO_DOWNLOAD_SOCKET_TIMEOUT', 30),

        // Refuse download URLs that resolve to a private, loopback, link-local or
        // otherwise reserved address.
        //
        // yt-dlp runs inside the container, so without this the downloader
        // permission also means "may make the server fetch anything it can reach"
        // - cloud metadata, the database, a service on the Docker network.
        //
        // Turn it off only if you deliberately download from a host on your own
        // network, and only if you trust everyone holding the permission.
        'block_private_hosts' => (bool) env('MINIZO_BLOCK_PRIVATE_HOSTS', true),

        // Optional explicit binary locations. Both are on PATH in the Docker
        // images, so these only matter for a bare-metal install.
        'yt_dlp_binary' => env('MINIZO_YT_DLP_BINARY'),
        'ffmpeg_location' => env('MINIZO_FFMPEG_LOCATION'),

        // The library exposes no process timeout, so a wedged yt-dlp is only
        // detected by its progress going stale for this long.
        'stall_timeout' => (int) env('MINIZO_DOWNLOAD_STALL_TIMEOUT', 900),

        // Minimum seconds between progress writes. yt-dlp emits progress many
        // times a second; persisting every line would hammer the database for
        // no visible benefit.
        'progress_throttle' => (float) env('MINIZO_DOWNLOAD_PROGRESS_THROTTLE', 1.0),

        // How long finished rows stay in "Recent activity".
        'history_days' => (int) env('MINIZO_DOWNLOAD_HISTORY_DAYS', 30),

        // How long a finished row lingers in the live queue widget before it is
        // only reachable through Recent activity. Without a linger a download
        // would vanish the instant it succeeded, which reads as a failure.
        'queue_linger' => (int) env('MINIZO_DOWNLOAD_QUEUE_LINGER', 600),

        // Rows shown under "Recent activity".
        'recent_limit' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Shares
    |--------------------------------------------------------------------------
    |
    | Public links are unauthenticated, so everything here is a safety limit.
    | The global on/off switch is NOT here — it must be editable by an admin at
    | runtime, so it lives in the `settings` table behind App\Support\Settings.
    |
    */

    'shares' => [
        // Instance-wide kill switch, read through App\Support\Sharing.
        //
        // This is only the DEFAULT. The design puts this toggle in the Users
        // screen, so an admin must be able to flip it at runtime — which means its
        // real home is the `settings` table, added with the rest of the sharing
        // feature. Until then Sharing::enabled() falls back to this value.
        'enabled' => (bool) env('MINIZO_SHARING_ENABLED', true),

        'token_length' => 12,

        // Dead links (expired or revoked) are kept this long so the Share links
        // screen can still show what was shared, then pruned.
        'retention_days' => (int) env('MINIZO_SHARE_RETENTION_DAYS', 30),

        // Countdown turns amber below this many seconds remaining.
        'warning_threshold' => 3600,

        // Requests per minute per IP against a public share page.
        'rate_limit' => (int) env('MINIZO_SHARE_RATE_LIMIT', 60),

        /*
         * Whether disabling a user account also revokes their live share links.
         *
         * False on purpose: disabling is about revoking LOGIN, not about un-publishing what
         * someone already handed out — a link shared with a colleague does not stop being
         * something that colleague was meant to have because the sender left.
         *
         * Turn it on where an account is more likely to be disabled because it was
         * COMPROMISED, in which case every link it published is suspect.
         *
         * Revoked, not deleted, so the 30-day audit trail survives; and re-enabling the
         * account does not bring them back. Read by ShareService::revokeForUser().
         */
        'revoke_on_user_disable' => (bool) env('MINIZO_SHARE_REVOKE_ON_USER_DISABLE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | MusicBrainz search
    |--------------------------------------------------------------------------
    |
    | Search runs as up to four passes (release, recording, standalone probe,
    | then a dismax fallback), each cached. See the service for why the legacy
    | single-query approach returned ~583,000 rows for a one-track lookup.
    |
    */

    'musicbrainz' => [
        // Hard floor between outbound requests, in milliseconds. MusicBrainz
        // permits one per second and blocks IPs that exceed it.
        'min_request_interval' => (int) env('MINIZO_MUSICBRAINZ_INTERVAL', 1100),

        'timeout' => (int) env('MINIZO_MUSICBRAINZ_TIMEOUT', 15),
        'retries' => (int) env('MINIZO_MUSICBRAINZ_RETRIES', 2),

        // Ceiling on a downloaded cover, in bytes. The fetch buffers the whole
        // response into memory, and the allow-list includes archive.org, which
        // serves arbitrary user-uploaded objects of any size. 15 MB is well
        // above any real front cover. 0 disables the check.
        'max_cover_bytes' => (int) env('MINIZO_MAX_COVER_BYTES', 15_728_640),

        // Search responses are immutable enough to cache aggressively.
        'cache_ttl' => (int) env('MINIZO_MUSICBRAINZ_CACHE_TTL', 86400),

        'search_limit' => 25,
        'recording_search_limit' => 100,

        // Candidates shown in step 1 after merging and ranking the passes.
        'max_candidates' => 40,

        // Per-user searches per minute, so one impatient user cannot spend the
        // whole instance's rate budget.
        'user_rate_limit' => (int) env('MINIZO_MUSICBRAINZ_USER_RATE_LIMIT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feed
    |--------------------------------------------------------------------------
    */

    'feed' => [
        // Releases shown per followed artist on the Feed screen.
        'releases_per_artist' => 6,

        // Releases fetched per artist per sync, and how many pages to walk.
        'import_limit' => (int) env('MINIZO_FEED_IMPORT_LIMIT', 50),
        'max_pages' => (int) env('MINIZO_FEED_MAX_PAGES', 3),

        // A release stays flagged "new" for this long after we first see it, so a
        // user who does not open the Feed for a few days still notices it.
        'new_for_days' => (int) env('MINIZO_FEED_NEW_FOR_DAYS', 14),

        // Ignore anything dated before this — a newly followed artist would
        // otherwise import a decades-deep back catalogue as "new releases".
        'backfill_days' => (int) env('MINIZO_FEED_BACKFILL_DAYS', 365),

        // Applied with a queue RateLimited middleware, never sleep() inside the
        // worker (which is what the legacy Last.fm job did).
        'requests_per_minute' => (int) env('MINIZO_FEED_REQUESTS_PER_MINUTE', 60),

        'queue' => env('MINIZO_FEED_QUEUE', 'default'),

        /*
         * Both catalogue calls are cached, because both get repeated for reasons that have
         * nothing to do with the data changing: people retype the same artist name, and a
         * sync job and a page render can ask for the same releases seconds apart. At one
         * artist per request against a rate-limited API, a duplicate call is a real cost.
         *
         * Search is cached longer than releases: a search result is a name and a picture,
         * which do not change, whereas new releases are the entire point of the feature.
         */
        'search_cache_ttl' => (int) env('MINIZO_FEED_SEARCH_CACHE_TTL', 3600),
        'releases_cache_ttl' => (int) env('MINIZO_FEED_RELEASES_CACHE_TTL', 1800),

        // How stale an artist's releases may be before a scheduled sync refreshes them.
        'resync_after_minutes' => (int) env('MINIZO_FEED_RESYNC_AFTER', 360),

        // Artists refreshed per scheduled run, so one tick cannot queue a hundred jobs.
        'sync_batch' => (int) env('MINIZO_FEED_SYNC_BATCH', 10),

        // Searches per user per minute, so one impatient person cannot spend the whole
        // instance's Tidal rate budget.
        'user_search_rate_limit' => (int) env('MINIZO_FEED_USER_SEARCH_RATE_LIMIT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Page headings
    |--------------------------------------------------------------------------
    |
    | The title and subtitle shown in the app header, keyed by route name.
    |
    | Kept here rather than passed from each page because two of the six screens
    | are dynamic — Files shows the folder name, Feed names the user whose feed it
    | is — so a static layout prop would not cover them. Any {param} placeholder is
    | filled from the current route's parameters, which is what lets Files work
    | with no page-side code at all.
    |
    | A page can still override either line by passing :heading / :subheading to
    | the layout; see resources/views/components/app-header.blade.php.
    |
    */

    'pages' => [
        'download' => ['Download', 'Grab tracks from the web'],
        'files' => ['{directory}', 'Library folder'],
        'feed' => ['Feed', 'Artists you follow'],
        'shares' => ['Share links', 'Every public link created from this library'],
        'users' => ['Users', 'Accounts, folder access & permissions'],
        'settings.edit' => ['Settings', 'Profile, security & 2FA'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Per-user, editable in Settings. Bounded so a stored value cannot be used
    | to ask for an unbounded page.
    |
    */

    'pagination' => [
        'default' => 50,
        'min' => 10,
        'max' => 200,
        'options' => [20, 50, 100, 200],
    ],

    /*
     * TRUSTED_PROXIES is deliberately NOT a key here.
     *
     * It is read in bootstrap/app.php, which runs before the configuration is loaded, so
     * a key in this file could never be the thing that configures it. Putting one here
     * anyway would be a config option nothing reads.
     */

];
