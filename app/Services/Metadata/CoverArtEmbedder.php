<?php

namespace App\Services\Metadata;

use App\Exceptions\MetadataException;
use App\Support\TrackMetadata;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CoverArtEmbedder
{
    private const TIMEOUT = 30;

    /** Embeds cover art into a FLAC through metaflac. */
    public function __construct(
        private Metaflac $metaflac,
    ) {}

    /** Whether metaflac is present, and so whether covers can be embedded. */
    public function available(): bool
    {
        return $this->metaflac->available();
    }

    /**
     * Download a cover and embed it.
     *
     * @throws MetadataException
     */
    public function embed(string $flacPath, string $coverUrl): void
    {
        if (! $this->metaflac->available()) {
            throw MetadataException::coverToolUnavailable();
        }

        $image = $this->fetch($coverUrl);

        // metaflac reads the picture from a path and has no stdin mode. Written to the
        // system temp dir so a failure leaves no debris in the music folder.
        $temp = tempnam(sys_get_temp_dir(), 'minizo-cover-');

        if ($temp === false) {
            throw MetadataException::coverEmbedFailed('could not create a temporary file');
        }

        try {
            file_put_contents($temp, $image);

            // Remove then import, in one invocation and applied in order: metaflac
            // appends otherwise, so re-tagging embeds a second cover. --dont-use-padding
            // on the removal avoids a hole it would have to rewrite the file to fill.
            $this->metaflac->run([
                '--remove', '--block-type=PICTURE', '--dont-use-padding', $flacPath,
            ]);

            $this->metaflac->run(['--import-picture-from='.$temp, $flacPath]);
        } catch (MetadataException $e) {
            // metaflac refuses an image whose resolution it cannot read. That is a fault
            // of the download, not the FLAC, so it fails the cover without losing tags.
            throw MetadataException::coverEmbedFailed($e->getMessage());
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @throws MetadataException
     */
    private function fetch(string $url): string
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withUserAgent((string) config('services.musicbrainz.user_agent'))
                ->get($url);
        } catch (ConnectionException) {
            throw MetadataException::coverFetchFailed();
        }

        if (! $response->successful() || $response->body() === '') {
            throw MetadataException::coverFetchFailed();
        }

        /*
         * Re-checked AFTER the request, because redirects are followed.
         *
         * The URL was allow-listed before we got here, but archive.org answers cover
         * requests with a redirect to its storage hosts - so the host that actually served
         * these bytes is not necessarily the one that was approved.
         */
        if (! TrackMetadata::isAllowedCoverHost((string) $response->effectiveUri())) {
            throw MetadataException::coverFetchFailed();
        }

        /*
         * A size ceiling, because body() has already buffered the whole response into
         * memory. The allow-list permits archive.org, which serves arbitrary user-uploaded
         * objects of any size, and the URL is chosen by the client - so without a cap a
         * multi-gigabyte "cover" is a way to exhaust the worker.
         *
         * Checked on the buffer rather than on Content-Length: a header can lie.
         */
        $body = $response->body();

        $limit = (int) config('minizo.musicbrainz.max_cover_bytes', 15_728_640);

        if ($limit > 0 && strlen($body) > $limit) {
            throw MetadataException::coverFetchFailed();
        }

        return $body;
    }
}
