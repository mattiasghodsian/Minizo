<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class MusicService
{
    /**
     * The restrictions for the file extensions is duo to the fact that
     * php-audio library cant write all metadata to all file types.
     */
    public const ALLOWED_EXTENSIONS = ['mp3', 'flac', 'm4a'];

    /**
     * Get all directories from the music storage folder
     *
     * @return array
     */
    public function getAllDirectories(): array
    {
        $directories    = [];
        $disk           = Storage::disk('local');
        $allDirectories = $disk->allDirectories('music');

        foreach ($allDirectories as $directory) {
            $directories[] = [
                'path' => $directory,
                'name' => basename($directory),
                'full_path' => $disk->path($directory)
            ];
        }
        
        return $directories;
    }

    /**
     * Get all music files in a directory
     *
     * @param string $directory
     * @return array
     */
    public function getFilesInDirectory(string $directory): array
    {
        $files      = [];
        $disk       = Storage::disk('local');
        $musicPath  = "music/$directory";

        if (!$disk->exists($musicPath)) {
            return [];
        }

        $allFiles = $disk->files($musicPath);

        foreach ($allFiles as $file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            
            if (in_array(strtolower($extension), self::ALLOWED_EXTENSIONS)) {
                $fileSize = $disk->size($file);
                $files[] = [
                    'name'          => basename($file),
                    'path'          => $file,
                    'full_path'     => $disk->path($file),
                    'format'        => $extension,
                    'size'          => round($fileSize / 1024 / 1024, 2) . ' MB',
                    'size_bytes'    => $fileSize,
                    'last_modified' => date('Y-m-d H:i:s', $disk->lastModified($file))
                ];
            }
        }

        return $files;
    }

    public function deleteFile(string $directory, string $file)
    {
        $disk       = Storage::disk('local');
        $musicPath  = "music/$directory";
        $filePath = "$musicPath/$file";

        if (!$disk->exists($musicPath)) {
            throw new \Exception("Directory not found: $directory");
        }

        if (!$disk->exists($filePath)) {
            throw new \Exception("File not found: $file");
        }

        try {
            $disk->delete($filePath);
            
            return [
                'success' => true,
                'message' => "File successfully deleted: $file",
            ];
        } catch (\Exception $e) {
            $error = "Failed to delete file: {$e->getMessage()}";
            throw new \Exception($error);

            return [
                'success' => false,
                'message' => $error,
            ];
        }
    }
}