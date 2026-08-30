<?php

namespace App\Services\Sharing;

use App\Models\Share;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class ShareArchiveStreamer
{
    /** Streams a shared folder as a zip. */
    public function __construct(
        private ShareService $shares,
    ) {}

    /** A zip of every track in the share, written as it is read. */
    public function stream(Share $share): StreamedResponse
    {
        $files = $this->shares->contents($share);
        $disk = Storage::disk((string) config('minizo.library.disk', 'music'));

        return response()->stream(function () use ($share, $files, $disk): void {
            $zip = new ZipStream(
                outputName: $share->archiveFilename(),
                // We send our own headers on the StreamedResponse; letting ZipStream send
                // them too would emit them twice.
                sendHttpHeaders: false,
                // STORE, not DEFLATE. FLAC is already compressed, so deflate spends real
                // CPU per gigabyte to save a fraction of a percent - and on a self-hosted
                // box that CPU is competing with the download that is being served.
                defaultCompressionMethod: CompressionMethod::STORE,
            );

            foreach ($files as $file) {
                $handle = $disk->readStream($file->path());

                // A file that vanished between the listing and the read is skipped rather
                // than fatal: aborting mid-stream would leave the visitor with a corrupt
                // archive and no explanation.
                if ($handle === null) {
                    continue;
                }

                $zip->addFileFromStream(
                    fileName: $file->filename,
                    stream: $handle,
                    lastModificationDateTime: $file->modifiedAt,
                );

                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            $zip->finish();
        }, 200, $this->headers($share));
    }

    /**
     * @return array<string, string>
     */
    private function headers(Share $share): array
    {
        return [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.addslashes($share->archiveFilename()).'"',

            // Nothing about a link that can be revoked should sit in a shared cache.
            'Cache-Control' => 'no-store, private',

            // Nginx and friends buffer a response with no Content-Length by default,
            // which would defeat the streaming entirely - the whole archive would be
            // assembled in the proxy before anything reached the client.
            'X-Accel-Buffering' => 'no',
        ];
    }
}
