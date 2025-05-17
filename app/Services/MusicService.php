<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kiwilan\Audio\Audio;

class MusicService
{
    public const ALLOWED_EXTENSIONS = ['mp3', 'flac', 'm4a'];

    /**
     * Get all directories from the music storage folder
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

    /**
     * Get the full path to a file in the music storage
     * @throws \Exception
     */
    public function getFullPath(string $directory, string $file): string
    {
        try {
            $disk = Storage::disk('music');
            $path = "/$directory/$file";

            if (!$disk->exists($path)) {
                throw new \Exception("File not found: $path");
            }

            return $disk->path($path);
        } catch (\Exception $e) {
            Log::error('Failed to get full path', [
                'directory' => $directory,
                'file' => $file,
                'error' => $e->getMessage()
            ]);
            throw new \Exception("Failed to get full path: {$e->getMessage()}");
        }
    }

    /**
     * Write metadata to a file
     */
    public function writeMetadata(string $directory, string $file, array $metadata, string $extension): bool
    {
        try {
            if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
                throw new \Exception("File type not allowed: {$extension}");
            }

            $fullPath = $this->getFullPath($directory, $file);
            $audio = Audio::read($fullPath);

            // Start write operation
            $tag = $audio->write()
                ->title($metadata['title'] ?? '')
                ->artist($metadata['artist'] ?? '')
                ->album($metadata['album'] ?? '')
                ->genre($metadata['genre'] ?? '')
                ->year(substr($metadata['year'] ?? '', 0, 4))
                ->trackNumber(
                    ($metadata['track_number'] ?? '') . 
                    '/' . 
                    ($metadata['total_tracks'] ?? '')
                )
                ->albumArtist($metadata['album_artist'] ?? $metadata['artist'] ?? '')
                ->composer($metadata['composer'] ?? '')
                ->comment(sprintf(
                    "Updated via Minizo \n%s",
                    "https://github.com/mattiasghodsian/Minizo",
                ));

            // Handle cover art if URL is provided
            if (!empty($metadata['cover_art'])) {
                try {
                    $coverContent = file_get_contents($metadata['cover_art']);
                    if ($coverContent !== false) {
                        $tag->cover($coverContent);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to set cover art', [
                        'error' => $e->getMessage(),
                        'file' => $file
                    ]);
                }
            }

            $tag->save();

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to write metadata', [
                'directory' => $directory,
                'file' => $file,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}