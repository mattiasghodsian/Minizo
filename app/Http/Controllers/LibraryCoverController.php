<?php

namespace App\Http\Controllers;

use App\Services\Library\FileService;
use App\Services\Metadata\AudioTagReader;
use App\Support\LibraryFolder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LibraryCoverController extends Controller
{
    /** Serve the artwork embedded in one library track. */
    public function __invoke(
        Request $request,
        string $directory,
        string $filename,
        FileService $files,
        AudioTagReader $reader,
    ): SymfonyResponse {
        // tryMake, not the constructor: an invalid name is a request for something that
        // does not exist, so it 404s. The constructor throws, which surfaced a 500 and a
        // log entry for what is usually a stale URL or a probe.
        $folder = LibraryFolder::tryMake($directory);

        abort_if($folder === null, 404);

        // The folder gate first. A cover is content, so seeing one requires the same
        // access as seeing the file it came from.
        Gate::authorize('view', $folder);

        // Re-resolved against the folder's real contents, never joined onto a path.
        $file = $files->find($folder, $filename);

        abort_if($file === null, 404);

        $fingerprint = $reader->fingerprint($file);

        abort_if($fingerprint === null, 404);

        // ETag from (path, mtime, size), so a re-tag invalidates it. The browser is where
        // cover bytes are cached; the application cache is the database by default.
        $etag = '"'.$fingerprint.'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->noContent(SymfonyResponse::HTTP_NOT_MODIFIED, ['ETag' => $etag]);
        }

        // Asked before reading any bytes. hasCover() answers from the tag cache, where
        // cover() re-runs a full getID3 analyse of a 30-40 MB FLAC every single time.
        // The listing emits a cover URL for every row without knowing which files have
        // artwork, so the miss is the common case, not the exception.
        if (! $reader->hasCover($file)) {
            return $this->missing();
        }

        $cover = $reader->cover($file);

        // Reachable when the tags claim a picture the reader cannot extract.
        if ($cover === null) {
            return $this->missing();
        }

        return new Response($cover['contents'], SymfonyResponse::HTTP_OK, [
            'Content-Type' => $cover['mime'],
            'Content-Length' => (string) strlen($cover['contents']),
            'ETag' => $etag,

            // private, because this is library content behind a login and must not sit in
            // a shared proxy. immutable is still safe: the ETag changes when the file does.
            'Cache-Control' => 'private, max-age=604800, immutable',
        ]);
    }

    /**
     * No artwork on this track.
     *
     * Briefly cacheable rather than a bare abort(404). An untagged file is asked about
     * on every render and every scroll, and a 404 with no headers is re-requested every
     * time. The window is short because the URL carries no version: tag the file and
     * the same URL starts serving a picture, so a long-lived negative cache would hide
     * it until the entry expired.
     */
    private function missing(): SymfonyResponse
    {
        return response()->noContent(SymfonyResponse::HTTP_NOT_FOUND, [
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
