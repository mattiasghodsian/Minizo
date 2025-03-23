<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use App\Services\MusicService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class LibraryController extends Controller
{
    protected MusicService $musicService;

    public function __construct(MusicService $musicService)
    {
        $this->musicService = $musicService;
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
            'messageType'       => session('messageType')
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
        $request->validate([
            'currentFile' => 'required|string',
            'fromDirectory' => 'required|string',
            'toDirectory' => 'required|string',
        ]);
    
        try {
            $success = $this->musicService->moveFile(
                $request->currentFile,
                $request->fromDirectory,
                $request->toDirectory
            );
            
            if ($success) {
                return redirect()->back()->with([
                    'messageType' => sprintf('File successfully moved to %s_%s', $request->toDirectory, uniqid()),
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

}
