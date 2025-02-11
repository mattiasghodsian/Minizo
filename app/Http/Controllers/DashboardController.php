<?php

namespace App\Http\Controllers;

use App\Services\MusicService;
use App\Services\DownloadService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    protected MusicService $musicService;
    protected DownloadService $downloadService;

    public function __construct(MusicService $musicService, DownloadService $downloadService)
    {
        $this->musicService     = $musicService;
        $this->downloadService  = $downloadService;
    }

    public function get(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'formats' => $this->musicService::ALLOWED_EXTENSIONS,
            'downloadHistory' => [
                // [
                //     'file' => 'somne asian song.mp3',
                //     'url' =>'https://music.youtube.com/watch?v=xxxxxxxxxxx',
                //     'directory' => 'asian',
                //     'format' => 'mp3',
                //     'timestamp' => '2021-10-10 10:09:10',
                // ],
                // [
                //     'file' => 'somne rock song.flac',
                //     'url' =>'https://music.youtube.com/watch?v=xxxxxxxxxxx',
                //     'directory' => 'rock',
                //     'format' => 'flac',
                //     'timestamp' => '2021-10-10 10:10:10',
                // ],
            ]
        ]);
    }

    public function post(Request $request): Response
    {
        // throw ValidationException::withMessages([
        //     'password' => __('auth.password'),
        // ]);

        // TODO: Validate the request
        $url        = $request->url;
        $directory  = $request->directory;
        $format     = $request->format;

        return Inertia::render('Dashboard', [
            'file' =>  $this->downloadService->getSong($format, $url, $directory)
        ]);
    }

}
