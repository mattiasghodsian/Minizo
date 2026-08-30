<?php

namespace App\Services\Download;

use App\Exceptions\DownloadCancelled;
use App\Exceptions\DownloadException;
use App\Support\DownloadProgress;
use App\Support\DownloadRequest;
use App\Support\DownloadResult;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use YoutubeDl\Entity\Video;
use YoutubeDl\Exception\ExecutableNotFoundException;
use YoutubeDl\YoutubeDl;

class YtDlpAudioDownloader implements AudioDownloader
{
    /**
     * @param  Closure(): YoutubeDl  $factory
     */
    public function __construct(
        private Closure $factory,
        private YoutubeDlOptionsFactory $options,
    ) {}

    /**
     * @param  (callable(DownloadProgress): void)|null  $onProgress
     */
    public function download(DownloadRequest $request, ?callable $onProgress = null): DownloadResult
    {
        $disk = Storage::disk(config('minizo.library.disk', 'music'));

        // yt-dlp writes the file itself, so it needs a real path - and the folder
        // must exist before it starts, or it fails on the output template.
        if (! $disk->exists($request->folder->path())) {
            $disk->makeDirectory($request->folder->path());
        }

        $downloadPath = $disk->path($request->folder->path());

        // Remembered from the progress lines so a cancellation can clean up after
        // itself; yt-dlp leaves partial files behind when its process dies.
        $target = null;

        $youtubeDl = ($this->factory)();

        $binary = (string) config('minizo.downloads.yt_dlp_binary', '');

        if ($binary !== '') {
            $youtubeDl->setBinPath($binary);
        }

        $youtubeDl->onProgress(function (
            ?string $progressTarget,
            string $percentage,
            ?string $size = null,
            ?string $speed = null,
            ?string $eta = null,
            ?string $totalTime = null,
        ) use ($onProgress, &$target): void {
            $target ??= $progressTarget;

            if ($onProgress === null) {
                return;
            }

            // Throwing DownloadCancelled from in here is how a cancel actually
            // stops the child process - see App\Exceptions\DownloadCancelled.
            $onProgress(DownloadProgress::fromCallback(
                $progressTarget, $percentage, $size, $speed, $eta,
            ));
        });

        try {
            $collection = $youtubeDl->download($this->options->for($request, $downloadPath));
        } catch (DownloadCancelled $e) {
            $this->cleanUpPartials($disk, $request, $target);

            throw $e;
        } catch (ExecutableNotFoundException) {
            throw DownloadException::notConfigured();
        }

        $video = $this->firstVideo($collection->getVideos());

        if ($video->getError() !== null) {
            /*
             * Logged as well as shown. The message is surfaced because it is genuinely the
             * most useful thing a user can be told ("Video unavailable", "Sign in to
             * confirm your age"), and it is safe to surface because PublicUrl::isSafe has
             * already refused every host that is not on the public internet - so it cannot
             * be used to describe the inside of the network.
             *
             * The log line is what an operator reads when the surfaced text is not enough.
             */
            Log::warning('yt-dlp reported an error.', [
                'url' => $request->url,
                'error' => $video->getError(),
            ]);

            throw DownloadException::remote($video->getError());
        }

        // getFilename(), not getFile(): getFile() is typed SplFileInfo but returns the
        // raw metadata value, so it TypeErrors when yt-dlp printed no destination.
        $reported = $video->getFilename();

        if ($reported === null) {
            throw DownloadException::producedNothing();
        }

        $filename = basename($reported);

        // yt-dlp reports the destination it intended, and ExtractAudio changes the
        // extension afterwards. A missing file here usually means ffmpeg is absent.
        if (! $disk->exists($request->folder->path().'/'.$filename)) {
            $filename = $this->findConvertedFile($disk, $request, $filename)
                ?? throw DownloadException::producedNothing();
        }

        return new DownloadResult(
            filename: $filename,
            title: $video->getTrack() ?? $video->getTitle(),
            artist: $video->getArtist() ?? $video->getUploader(),
            bytes: $disk->size($request->folder->path().'/'.$filename),
        );
    }

    /** Whether yt-dlp can be found on this server. */
    public function configured(): bool
    {
        $binary = config('minizo.downloads.yt_dlp_binary');

        if (filled($binary)) {
            return is_executable((string) $binary);
        }

        return (new ExecutableFinder)->find('yt-dlp') !== null;
    }

    /**
     * @param  array<int, Video>  $videos
     */
    private function firstVideo(array $videos): Video
    {
        // noPlaylist means there is at most one. An empty collection is the
        // library's way of saying it never saw a metadata file, i.e. yt-dlp never
        // got as far as resolving the URL.
        return $videos[0] ?? throw DownloadException::producedNothing();
    }

    /** Find the postprocessed file when the reported name no longer exists. */
    private function findConvertedFile(Filesystem $disk, DownloadRequest $request, string $reported): ?string
    {
        $stem = pathinfo($reported, PATHINFO_FILENAME);
        $expected = $stem.'.'.$request->format->extension();

        return $disk->exists($request->folder->path().'/'.$expected) ? $expected : null;
    }

    /** Remove the fragments a killed yt-dlp leaves behind. */
    private function cleanUpPartials(Filesystem $disk, DownloadRequest $request, ?string $target): void
    {
        if ($target === null) {
            return;
        }

        foreach (['.part', '.ytdl'] as $suffix) {
            $path = $request->folder->path().'/'.$target.$suffix;

            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }
}
