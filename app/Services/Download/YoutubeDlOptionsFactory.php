<?php

namespace App\Services\Download;

use App\Exceptions\DownloadException;
use App\Support\DownloadRequest;
use YoutubeDl\Options;

class YoutubeDlOptionsFactory
{
    /** The filename template. */
    public const OUTPUT_TEMPLATE = '%(artist,artists,uploader|Unknown Artist)s - %(title)s.%(ext)s';

    public function for(DownloadRequest $request, string $downloadPath): Options
    {
        // Options::url() requires a non-empty string, and this class is reachable
        // without going through DownloadQueue.
        if ($request->url === '') {
            throw DownloadException::invalidUrl();
        }

        $options = Options::create()
            ->downloadPath($downloadPath)
            ->output(self::OUTPUT_TEMPLATE)
            ->url($request->url)

            // A URL copied from a playlist carries "&list=" and would otherwise fetch
            // every track under one queue row. Takes no argument, unlike its neighbours.
            ->noPlaylist()

            ->format('bestaudio/best')

            // Highest bitrate, then highest sample rate. Sort fields default to
            // descending, so there is no "+" prefix.
            ->formatSort(['abr', 'asr'])

            ->extractAudio(true)
            ->audioFormat($request->format->value)

            // Embedded rather than written beside the track; the library has no
            // notion of sidecar files.
            ->embedThumbnail(true)
            ->convertThumbnail('jpg')

            // yt-dlp's name for --add-metadata. There is no embedMetadata().
            ->addMetadata(true)

            // Not restrictFileNames(), which transliterates to ASCII and would turn
            // "Perdonarte ¿Para Qué?" into "Perdonarte_Para_Que". This one only strips
            // the characters a Windows bind mount cannot write, and keeps Unicode.
            ->windowsFilenames(true)

            ->noOverwrites(true)

            ->retries((string) config('minizo.downloads.retries', '3'))
            ->fragmentRetries((string) config('minizo.downloads.fragment_retries', '10'))
            ->concurrentFragments((int) config('minizo.downloads.concurrent_fragments', 4))
            ->socketTimeout((int) config('minizo.downloads.socket_timeout', 30));

        // --audio-quality only affects lossy encoders. FLAC's equivalent is ffmpeg's
        // compression level, which has to go through the ExtractAudio postprocessor.
        if ($request->format->isLossless()) {
            $level = (int) config('minizo.downloads.flac_compression_level', 8);

            $options = $options->postProcessorArgs(
                'ExtractAudio:-compression_level '.max(0, min(12, $level))
            );
        } else {
            $options = $options->audioQuality('0');
        }

        $ffmpeg = config('minizo.downloads.ffmpeg_location');

        if (filled($ffmpeg)) {
            $options = $options->ffmpegLocation((string) $ffmpeg);
        }

        return $options;
    }
}
