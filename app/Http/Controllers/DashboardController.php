<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use App\Jobs\DownloadJob;
use App\Helper\QueueHelper;
use Illuminate\Http\Request;
use App\Services\MusicService;
use Illuminate\Validation\Rule;
use App\Services\DownloadService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    protected MusicService $musicService;
    protected DownloadService $downloadService;
    protected DownloadJob $downloadJob;
    protected QueueHelper $queueHelper;

    public function __construct(
        MusicService $musicService,
        DownloadService $downloadService,
        QueueHelper $queueHelper
    ) {
        $this->musicService     = $musicService;
        $this->downloadService  = $downloadService;
        $this->queueHelper      = $queueHelper;
    }

    public function get(): Response
    {
        return Inertia::render('Dashboard', [
            'queues' =>  $this->queueHelper->getList(),
            'message'           => session('message'),
            'messageType'       => session('messageType')
        ]);
    }

    public function post(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'url'       => 'required|url',
                'directory' => 'required|string',
                'format'    => ['required', Rule::in($this->musicService::ALLOWED_EXTENSIONS)],
            ]);

            // Clean up the URL to remove the list query parameter
            $cleanUrl = preg_replace('/&list=.*/', '', $request->url);
            $request->merge(['url' => $cleanUrl]);

            DownloadJob::dispatch(
                $validated['format'],
                $validated['url'],
                $validated['directory']
            );

            return redirect()
                ->route('dashboard')
                ->with([
                    'message'       => sprintf('Song has been added to queue to be downloaded_%s', uniqid()),
                    'messageType'   => 'success'
                ]);
        } catch (ValidationException $e) {
            Log::error($e->getMessage(), ['exception' => $e, 'function' => __FUNCTION__]);
            return redirect()
                ->route('dashboard')
                ->with([
                    'message'       => sprintf('%s_%s', $e->getMessage(), uniqid()),
                    'messageType'   => 'error'
                ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['exception' => $e, 'function' => __FUNCTION__]);
            return redirect()
                ->route('dashboard')
                ->with([
                    'message'       => sprintf('%s_%s', $e->getMessage(), uniqid()),
                    'messageType'   => 'error'
                ]);
        }
    }
}
