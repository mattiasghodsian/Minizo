<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
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
        usort($directories, function ($a, $b) {
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
        usort($files, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $files;
    }

    /**
     * Delete file from a directory
     */
    public function deleteFile(string $directory, string $file): bool
    {
        try {
            $disk       = Storage::disk('music');
            $musicPath  = "/$directory";
            $filePath   = "$musicPath/$file";

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

    /**
     * Move a file from one directory to another
     *
     * @param string $currentFile
     * @param string $fromDirectory
     * @param string $toDirectory
     * @return bool
     */
    public function moveFile(string $currentFile, string $fromDirectory, string $toDirectory): bool
    {
        try {
            $disk               = Storage::disk('music');
            $sourcePath         = "/$fromDirectory/$currentFile";
            $destinationPath    = "/$toDirectory/$currentFile";

            if (!$disk->exists("/$fromDirectory")) {
                throw new \Exception("Source directory not found: $fromDirectory");
            }

            if (!$disk->exists("/$toDirectory")) {
                throw new \Exception("Destination directory not found: $toDirectory");
            }

            if (!$disk->exists($sourcePath)) {
                throw new \Exception("File not found: $currentFile");
            }

            if ($disk->exists($destinationPath)) {
                throw new \Exception("A file with the same name already exists in the destination directory");
            }

            return $disk->move($sourcePath, $destinationPath);

        } catch (\Exception $e) {
            Log::error("Exception while moving file", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $currentFile,
                'from' => $fromDirectory,
                'to' => $toDirectory
            ]);
            return false;
        }

    }
}