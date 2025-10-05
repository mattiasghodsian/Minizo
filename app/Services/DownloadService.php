<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use YoutubeDl\Options;
use YoutubeDl\YoutubeDl;

class DownloadService
{
    /**
     * The restrictions for the file extensions is duo to the fact that
     * php-audio library cant write all metadata to all file types.
     */
    public const ALLOWED_EXTENSIONS =  ['mp3', 'flac']; // ['mp3', 'flac', 'm4a'];
    private YoutubeDl $youtubeDl;
    
    public function __construct()
    {
        $this->youtubeDl = new YoutubeDl();
    }

    public function getSong(string $format, string $url, string $directory): array
    {
        try {
            $path = "music/$directory";
            $disk = Storage::disk('local');
            $downloadPath = $disk->path($path);
            $result = [];

            if (!$disk->exists($path)) {
                $disk->makeDirectory($path);
            }

            $collection = $this->youtubeDl->download(
                Options::create()
                    ->downloadPath($downloadPath)
                    ->extractAudio(true)
                    ->audioFormat($format)
                    ->audioQuality('0')
                    ->output('%(artists)s - %(title)s.%(ext)s')
                    ->url($url)
            );

            foreach ($collection->getVideos() as $video) {
                if ($video->getError() !== null) {
                    throw new \Exception($video->getError());
                } else {
                    $result = [
                        'file'      => $video->getFile(),
                        'title'     => $video->getTitle(),
                        'directory' => $path,
                    ];
                }
            }

            return $result;
        } catch (\Exception $e) {
            throw new \Exception("Failed to get song: {$e->getMessage()}");
        }
    }

    
}