<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Inertia\Inertia;

class LogViewerController extends Controller
{
    public function index()
    {
        $logPath = storage_path('logs');
        $files = File::files($logPath);
        
        $logs = collect($files)->map(function ($file) {
            return [
                'name' => $file->getFilename(),
                'content' => array_slice(file($file->getPathname(), FILE_IGNORE_NEW_LINES), -1000),
                'updated' => date('Y-m-d H:i:s', $file->getMTime())
            ];
        });

        return Inertia::render('Logs', [
            'logs' => $logs
        ]);
    }
}