<?php

namespace App\Services\Metadata;

use App\Exceptions\MetadataException;
use App\Support\TrackMetadata;

class FlacTagWriter
{
    /** Writes Vorbis comments to a FLAC through metaflac. */
    public function __construct(
        private Metaflac $metaflac,
    ) {}

    /**
     * @throws MetadataException
     */
    public function write(string $path, TrackMetadata $metadata): void
    {
        if (! $metadata->isWritable()) {
            throw MetadataException::nothingToWrite();
        }

        $arguments = [Metaflac::NO_TRANSCODE];

        foreach ($this->tags($metadata) as $key => $value) {
            $values = is_array($value) ? array_values(array_filter($value, 'filled')) : [$value];

            // Only fields with a value are touched: setting an empty tag replaces what
            // the file had with a present-but-blank field.
            if ($values === [] || ! filled($values[0])) {
                continue;
            }

            // Remove once, then set each value: a bare --set-tag appends, so re-tagging
            // would leave three TITLE fields. The same append is how a multi-value GENRE
            // is written, so the remove must stay outside the loop.
            $arguments[] = '--remove-tag='.$key;

            foreach ($values as $single) {
                $arguments[] = '--set-tag='.$key.'='.$single;
            }
        }

        $arguments[] = $path;

        try {
            $this->metaflac->run($arguments);
        } catch (MetadataException $e) {
            // The metaflac message is useful for a log but not for a user; the cause is
            // preserved so it still reaches one.
            throw new MetadataException(
                MetadataException::writeFailed(basename($path))->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * The Vorbis comment fields Minizo writes.
     *
     * @return array<string, string|int|array<int, string>|null>
     */
    private function tags(TrackMetadata $metadata): array
    {
        return [
            'TITLE' => $metadata->title,
            'ARTIST' => $metadata->artist,
            'ALBUMARTIST' => $metadata->albumArtist ?? $metadata->artist,
            'ALBUM' => $metadata->album,
            'DATE' => $metadata->year,
            'GENRE' => $metadata->genres,
            'TRACKNUMBER' => $metadata->trackNumber,

            // Both spellings: TRACKTOTAL is what most taggers write, TOTALTRACKS what
            // the spec suggests, and players disagree about which they read.
            'TOTALTRACKS' => $metadata->totalTracks,
            'TRACKTOTAL' => $metadata->totalTracks,

            'ISRC' => $metadata->isrc,
            'BARCODE' => $metadata->barcode,
            'LABEL' => $metadata->label,
            'MEDIA' => $metadata->mediaFormat,
            'RELEASESTATUS' => $metadata->status,
            'RELEASECOUNTRY' => $metadata->country,
            'LANGUAGE' => $metadata->language,
            'MUSICBRAINZ_TRACKID' => $metadata->recordingId,
            'MUSICBRAINZ_ALBUMID' => $metadata->releaseId,

            // Marks the file as ours. Useful in practice: it is how you tell, months
            // later, whether a file's tags came from Minizo or from whatever wrote them
            // before.
            'COMMENT' => 'Tagged by Minizo · https://github.com/mattiasghodsian/Minizo',
        ];
    }
}
