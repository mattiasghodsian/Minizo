<?php

namespace App\Http\Controllers;

use App\Models\Share;
use App\Services\Metadata\AudioTagReader;
use App\Services\Sharing\ShareArchiveStreamer;
use App\Services\Sharing\ShareService;
use App\Support\FileSize;
use App\Support\LibraryFile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicShareController extends Controller
{
    /** Serves the pages and files behind a public share link. */
    public function __construct(
        private ShareService $shares,
    ) {}

    /** The public page. */
    public function show(string $token): Response
    {
        $share = $this->shares->resolve($token);

        if ($share === null) {
            // 404 status with the designed body: correct for a crawler, and a real page
            // for a human. The undesigned-until-now expired state.
            return response()->view('pages.share.expired', status: 404);
        }

        $share->recordAccess();

        $files = $this->shares->contents($share);

        return response()->view('pages.share.show', [
            'share' => $share,
            'files' => $files,
            'meta' => $this->metaLine($share, $files),
            'hasCover' => $this->hasCover($share, $files),
        ]);
    }

    /**
     * The summary shown under the title: "12 tracks · 0.47 GB · FLAC".
     *
     * @param  array<int, LibraryFile>  $files
     * @return array<int, string>
     */
    private function metaLine(Share $share, array $files): array
    {
        $bytes = array_sum(array_map(fn (LibraryFile $file): int => $file->bytes, $files));

        $formats = collect($files)
            ->map(fn (LibraryFile $file): string => $file->formatLabel())
            ->unique()
            ->sort()
            ->implode(' / ');

        return array_values(array_filter([
            $share->type->isCollection()
                ? trans_choice(':count track|:count tracks', count($files), ['count' => count($files)])
                : null,
            FileSize::label($bytes),
            $formats !== '' ? $formats : null,
        ]));
    }

    /** The artwork route, for a single-track share. */
    public function cover(string $token, AudioTagReader $reader): SymfonyResponse
    {
        $share = $this->shares->resolve($token);

        // The same 404 as everything else on a dead link: an artwork endpoint that
        // answered differently would leak whether a token was ever valid.
        if ($share === null || $share->type->isCollection()) {
            abort(404);
        }

        $files = $this->shares->contents($share);

        abort_if($files === [], 404);

        // hasCover() reads the tag cache; cover() re-parses the whole FLAC. The response
        // is no-store, so without this check every view of a shared track pays a full
        // parse - on an endpoint a stranger can hit.
        abort_unless($reader->hasCover($files[0]), 404);

        $cover = $reader->cover($files[0]);

        abort_if($cover === null, 404);

        return new Response($cover['contents'], SymfonyResponse::HTTP_OK, [
            'Content-Type' => $cover['mime'],
            'Content-Length' => (string) strlen($cover['contents']),

            // no-store, unlike the authenticated endpoint: this URL sits behind a
            // revocable link, and a cached copy would outlive the revocation.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Whether the page should show real artwork instead of the generated tile.
     *
     * @param  array<int, LibraryFile>  $files
     */
    private function hasCover(Share $share, array $files): bool
    {
        return ! $share->type->isCollection()
            && $files !== []
            && app(AudioTagReader::class)->hasCover($files[0]);
    }

    /** "Download all (.zip)" for a folder, or the single file for a track share. */
    public function download(string $token, ShareArchiveStreamer $streamer): StreamedResponse|Response
    {
        $share = $this->shares->resolve($token);

        if ($share === null) {
            return response()->view('pages.share.expired', status: 404);
        }

        $share->recordAccess();

        if ($share->type->isCollection()) {
            return $streamer->stream($share);
        }

        $files = $this->shares->contents($share);

        return $files === []
            ? response()->view('pages.share.expired', status: 404)
            : $this->fileResponse($share, $files[0]);
    }

    /** One track out of a folder share - the per-row download icon on the public page. */
    public function track(string $token, string $filename): StreamedResponse|Response
    {
        $share = $this->shares->resolve($token);

        if ($share === null) {
            return response()->view('pages.share.expired', status: 404);
        }

        // Matched against the share's own contents rather than resolved against the
        // disk, so no path is ever built from the parameter.
        foreach ($this->shares->contents($share) as $file) {
            if ($file->filename === $filename) {
                $share->recordAccess();

                return $this->fileResponse($share, $file);
            }
        }

        return response()->view('pages.share.expired', status: 404);
    }

    /** Stream one shared file as a download. */
    private function fileResponse(Share $share, LibraryFile $file): StreamedResponse
    {
        // Streamed by the driver rather than read into memory; a FLAC is 30-40 MB and
        // one link can have several concurrent readers.
        return Storage::disk((string) config('minizo.library.disk', 'music'))
            ->download($file->path(), $file->filename, [
                // Nothing behind a revocable link belongs in a shared cache.
                'Cache-Control' => 'no-store, private',
            ]);
    }
}
