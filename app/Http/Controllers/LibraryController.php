<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use App\Services\MusicService;
use App\Helper\MusicBrainzHelper;
use App\Services\MetaDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class LibraryController extends Controller
{
    protected MusicService $musicService;
    protected MetaDataService $metadataService;
    protected MusicBrainzHelper $musicBrainz;

    public function __construct(MusicService $musicService, MetaDataService $metadataService)
    {
        $this->musicService = $musicService;
        $this->musicBrainz = new MusicBrainzHelper();
    }

    public function view(Request $request): Response
    {
        $directory  = $request->directory;
        $files      = $this->musicService->getFilesInDirectory($directory);
        
        return Inertia::render('Library/View', [
            'files'             => $files,
            'totalFiles'        => count($files),
            'currentDirectory'  => $directory,
            'message'           => session('message'),
            'messageType'       => session('messageType'),
            'results'           => session('results'),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'currentFile' => 'required|string',
                'directory' => 'required|string',
            ]);
    
            $isDeleted = $this->musicService->deleteFile(
                $validated['directory'],
                $validated['currentFile']
            );

            if ($isDeleted === false) {
                throw new \Exception('Failed to delete file');
            }

            Log::info('Song has been deleted', ['fields' => $validated, 'function' => __FUNCTION__]);
    
            return redirect()
                ->route('library', ['directory' => $validated['directory']])
                ->with([
                    'message'       => sprintf('Song has been deleted_%s', uniqid()),
                    'messageType'   => 'success'
                ]);
    
        } catch (ValidationException $e) {
            Log::error($e->getMessage(), ['exception' => $e, 'function' => __FUNCTION__]);
            return redirect()
                ->route('library', ['directory' => $request->directory])
                ->with([
                    'message'       => sprintf('%s_%s', $e->getMessage(), uniqid()),
                    'messageType'   => 'error'
                ]);
    
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['exception' => $e, 'function' => __FUNCTION__]);
            return redirect()
                ->route('library', ['directory' => $request->directory])
                ->with([
                    'message'       => sprintf('%s_%s', $e->getMessage(), uniqid()),
                    'messageType'   => 'error'
                ]);
        }
    }

    public function move(Request $request): RedirectResponse
    {
        try {
            $request->validate([
                'currentFile'   => 'required|string',
                'fromDirectory' => 'required|string',
                'toDirectory'   => 'required|string',
            ]);
        
            $success = $this->musicService->moveFile(
                $request->currentFile,
                $request->fromDirectory,
                $request->toDirectory
            );
            
            if ($success) {
                return redirect()->back()->with([
                    'message' => sprintf('File successfully moved to %s_%s', $request->toDirectory, uniqid()),
                    'messageType' => 'success'
                ]);
            } else {
                return redirect()->back()->with([
                    'message' => sprintf('Failed to move file_%s', uniqid()),
                    'messageType' => 'error'
                ]);
            }
        } catch (\Exception $e) {
            return redirect()->back()->with([
                'message' => sprintf('%s_%s', $e->getMessage(), uniqid()),
                'messageType' => 'error'
            ]);
        }
    }

    public function searchMetadata(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'search.title'  => 'required|string',
                'search.artist' => 'required|string',
            ]);

            $results = $this->musicBrainz->search(
                $request->input('search.artist'),
                $request->input('search.title')
            );

            if (empty($results)) {
                return response()->json([
                    'releases' => $releases ?? [],
                ]);
            }

            $releases = collect($results['releases'] ?? [])->map(function ($release)
            {
                $artistName = collect($release['artist-credit'] ?? [])->reduce(function ($carry, $credit) {
                    return $carry . $credit['name'] . ($credit['joinphrase'] ?? '');
                }, '');

                return [
                    'id'            => $release['id'],
                    'release_name'  => $release['title'],
                    'artist_name'   => $artistName,
                    'title'         => $release['title'],
                    'year'          => substr($release['date'] ?? '', 0, 4) ?: null,
                    'status'        => $release['status'] ?? '',
                    'country'       => $release['country'] ?? '',
                    'score'         => sprintf('%s%%', $release['score'] ?? 0),
                ];
            })->values()->all();

            return response()->json([
                'releases' => $releases
            ]);
    
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Search failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to fetch metadata',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getMetadata(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'releaseID' => 'required|string',
            ]);

            $releaseId = $request->input('releaseID');

            $metaData = $this->musicBrainz->getRelease($releaseId);
            if (empty($metaData)) {
                throw new \Exception('Failed to fetch metadata');
            }

            $covertArt = $this->musicBrainz->getCoverArt($releaseId);
            if (empty($covertArt)) {
                throw new \Exception('Failed to fetch cover art');
            }

            $image = Arr::get($covertArt, 'images.0.image', '');
            Arr::set($metaData, 'cover_art', $image);

            $metadataService = new MetaDataService($metaData);
            return response()->json($metadataService->parseData());
       } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Get metadata failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Get to update metadata',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateMetadata(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file'      => 'required|string',
                'directory' => 'required|string',
                'metaData'  => 'required|array',
            ]);

            $extension = strtolower(pathinfo($request->input('file'), PATHINFO_EXTENSION));
            if (!in_array($extension, MusicService::ALLOWED_EXTENSIONS)) {
                throw new \Exception("File type not allowed: .$extension");
            }

            $parsedData = $request->input('metaData');

            $check = $this->musicService->writeMetadata(
                $request->input('directory'),
                $request->input('file'),
                $parsedData,
                $extension
            );

            return response()->json($check);

       } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Update metadata failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to update metadata',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
