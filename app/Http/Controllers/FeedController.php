<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use App\Models\LastFmTrack;
use Illuminate\Support\Arr;
use App\Helper\LastFmHelper;
use App\Models\LastFmArtist;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class FeedController extends Controller
{
    private LastFmHelper $lastFmHelper;

    public function __construct(

    ) {
        $this->lastFmHelper = new LastFmHelper;
    }

    public function view(): Response
    {
        $artists = LastFmArtist::with(['tracks' => function($query) {
            $query->where('seen', false)->latest()->take(6);
        }])->orderBy('artist_name')->get();

        return Inertia::render('Feed', [
            'artists'           =>  $artists,
            'message'           => session('message'),
            'messageType'       => session('messageType')
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'artist'  => 'required|string',
            ]);

            $results = $this->lastFmHelper->searchArtist(
                $request->input('artist'),
            );

            if (empty($results)) {
                return response()->json([
                    'search' => [],
                ]);
            }

            $artists = Arr::get($results, 'results.artistmatches.artist') ?? [];

            foreach ($artists as $key => $artist)
            {
                $mbid = Arr::get($artist, 'mbid', '');
                if (empty($mbid)) {
                    continue;
                }
                // Replace the previous image array (lastfm removed images from API) with custom fetched image
                $artists[$key]['image'] = $this->lastFmHelper->getArtistImage(Arr::get($artist, 'url', ''));
            }

            return response()->json([
                'search' => $artists
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

    public function add(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'artist'        => 'required|string',
                'lastfm_url'    => 'required|string',
            ]);
            
            $response = LastFmArtist::firstOrCreate(
                [
                    'artist_name' => Arr::get($validated, 'artist'),
                    'lastfm_url' =>  Arr::get($validated, 'lastfm_url')
                ]
            );
            
            if ($response) {
                return redirect()->back()->with([
                    'message' => sprintf('Artist added successfully_%s', uniqid()),
                    'messageType' => 'success'
                ]);
            } else {
                return redirect()->back()->with([
                    'message' => sprintf('Failed to add artist_%s', uniqid()),
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

    public function seen(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'id'        => 'required|integer',
            ]);
            
            $response = LastFmTrack::where('id', Arr::get($validated, 'id'))->update([
                'seen' => true
            ]);

            if ($response) {
                return redirect()->back()->with([
                    'message' => sprintf('Track marked as seen_%s', uniqid()),
                    'messageType' => 'success'
                ]);
            } else {
                return redirect()->back()->with([
                    'message' => sprintf('Failed to mark track as seen_%s', uniqid()),
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

    public function remove($id): RedirectResponse
    {
        try {
            $artist = LastFmArtist::with('tracks')->find($id);
            if (!$artist) {
                 return redirect()->back()->with([
                    'message' => sprintf('Artist not found_%s', uniqid()),
                    'messageType' => 'error'
                ]);
            }

            $artistName = $artist->artist_name;
            $artist->tracks()->delete(); // deletes all related tracks
            $artist->delete();

            return redirect()->back()->with([
                'message' => sprintf('Artist %s removed successfully_%s', $artistName, uniqid()),
                'messageType' => 'success'
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with([
                'message' => sprintf('%s_%s', $e->getMessage(), uniqid()),
                'messageType' => 'error'
            ]);
        }
    }


}
