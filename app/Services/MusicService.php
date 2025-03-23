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
        $disk           = Storage::disk('music');
        $allDirectories = $disk->allDirectories();

        foreach ($allDirectories as $directory) {
            $directories[] = [
                'path' => $directory,
                'name' => basename($directory),
                'full_path' => $disk->path($directory)
            ];
        }

        // Sort alphabetically
        usort($directories, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
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
        $disk       = Storage::disk('music');
        $musicPath  = "/$directory";

        if (!$disk->exists($musicPath)) {
            return [];
        }

        $allFiles = $disk->files($musicPath);

        foreach ($allFiles as $file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            
            if (in_array(strtolower($extension), self::ALLOWED_EXTENSIONS)) {
                $fileSize = $disk->size($file);
                $fileName = basename($file);            
                $files[] = [
                    'name'          => $fileName,
                    'name_clean'    => pathinfo($fileName, PATHINFO_FILENAME),
                    'path'          => $file,
                    'full_path'     => $disk->path($file),
                    'format'        => $extension,
                    'size'          => round($fileSize / 1024 / 1024, 2) . ' MB',
                    'size_bytes'    => $fileSize,
                    'last_modified' => date('Y-m-d H:i:s', $disk->lastModified($file))
                ];
            }
        }

        // Sort alphabetically
        usort($files, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $files;
    }

    public function deleteFile(string $directory, string $file): bool
    {
        try {
            $disk       = Storage::disk('music');
            $musicPath  = "/$directory";
            $filePath = "$musicPath/$file";
    
            if (!$disk->exists($musicPath)) {
                throw new \Exception("Directory not found: $directory");
            }
    
            if (!$disk->exists($filePath)) {
                throw new \Exception("File not found: $file");
            }
            $disk->delete($filePath);
            
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Failed to delete file: {$e->getMessage()}");
        }
        
        return false;
    }

    public function moveFile(string $currentFile, string $fromDirectory, string $toDirectory): bool
    {
        // Implement the moveFile method
        return false;

    }
}