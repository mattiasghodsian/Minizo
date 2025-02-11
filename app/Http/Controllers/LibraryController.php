<?php

namespace App\Http\Controllers;

use App\Services\MusicService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LibraryController extends Controller
{
    protected MusicService $musicService;

    public function __construct(MusicService $musicService)
    {
        $this->musicService = $musicService;
    }

    public function view(Request $request): Response
    {
        $directory = $request->directory;
        $files = $this->musicService->getFilesInDirectory($directory);
        return Inertia::render('Library', [
            'files'             => $files,
            'totalFiles'        => count($files),
            'currentDirectory'  => $directory
        ]);
    }

}
