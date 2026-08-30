<?php

namespace Tests\Feature\Library;

use App\Models\User;
use App\Services\Library\FileService;
use App\Services\Library\FolderService;
use App\Support\LibraryCache;
use App\Support\LibraryFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The per-request memo that keeps one page load to a single scan of the music tree. */
class LibraryCacheTest extends TestCase
{
    use RefreshDatabase;

    private function library(array $folders = ['Spanish', 'Folk'], array $files = []): void
    {
        $disk = Storage::fake('music');

        foreach ($folders as $folder) {
            $disk->makeDirectory($folder);
        }

        foreach ($files as $path => $contents) {
            $disk->put($path, $contents);
        }

        // FileService authorizes internally, so these tests need an actor.
        $this->actingAs(User::factory()->create());
    }

    public function test_a_second_folder_listing_does_not_touch_the_disk(): void
    {
        $this->library(['Spanish']);

        $folders = app(FolderService::class);

        $this->assertSame(['Spanish'], $folders->names());

        // Created behind Minizo's back, after the first read.
        Storage::disk('music')->makeDirectory('AddedAfterwards');

        $this->assertSame(
            ['Spanish'],
            $folders->names(),
            'The second listing must come from the memo, not a fresh scan.',
        );
    }

    public function test_every_folder_lookup_in_one_request_shares_one_read(): void
    {
        $this->library(['Spanish']);

        $folders = app(FolderService::class);

        // What one page render actually does: sidebar, page body, policy check.
        $folders->all();
        Storage::disk('music')->makeDirectory('Sneaky');

        $this->assertCount(1, $folders->all());
        $this->assertSame(['Spanish'], $folders->names());
        $this->assertFalse($folders->exists('Sneaky'));
    }

    public function test_the_cache_is_a_singleton_so_callers_share_the_memo(): void
    {
        // If LibraryCache were bound per-resolution, each caller would get its own
        // empty memo and we would be back to the legacy per-caller rescan.
        $this->assertSame(app(LibraryCache::class), app(LibraryCache::class));
    }

    public function test_a_second_file_listing_does_not_touch_the_disk(): void
    {
        $this->library(['Spanish'], ['Spanish/a.flac' => 'x']);

        $folder = new LibraryFolder('Spanish');
        $files = app(FileService::class);

        $this->assertCount(1, $files->all($folder));

        Storage::disk('music')->put('Spanish/b.flac', 'y');

        $this->assertCount(1, $files->all($folder), 'second read should be memoised');
        $this->assertSame(1, $files->count($folder));
        $this->assertNull($files->find($folder, 'b.flac'));
    }

    public function test_folders_are_cached_independently_of_each_other(): void
    {
        $this->library(['Spanish', 'Folk'], [
            'Spanish/a.flac' => 'x',
            'Folk/b.flac' => 'y',
            'Folk/c.flac' => 'z',
        ]);

        $files = app(FileService::class);

        // Reading one folder must not poison another's listing.
        $this->assertCount(1, $files->all(new LibraryFolder('Spanish')));
        $this->assertCount(2, $files->all(new LibraryFolder('Folk')));
    }

    public function test_forgetting_a_key_forces_a_fresh_read(): void
    {
        $this->library(['Spanish']);

        $folders = app(FolderService::class);
        $this->assertSame(['Spanish'], $folders->names());

        Storage::disk('music')->makeDirectory('Folk');

        // This is what a write has to do. Without it, a newly created folder would
        // stay invisible until the TTL expired.
        app(LibraryCache::class)->forget('folders');

        $this->assertSame(['Folk', 'Spanish'], $folders->names());
    }

    public function test_flush_clears_folder_and_file_listings_together(): void
    {
        $this->library(['Spanish'], ['Spanish/a.flac' => 'x']);

        $folders = app(FolderService::class);
        $files = app(FileService::class);

        $folders->all();
        $files->all(new LibraryFolder('Spanish'));

        Storage::disk('music')->makeDirectory('Folk');
        Storage::disk('music')->put('Spanish/b.flac', 'y');

        // A rename or delete can invalidate the folder list AND file lists at once,
        // so flush is coarse: an extra scan is a slow page, a stale listing after a
        // write is a visible bug.
        app(LibraryCache::class)->flush();

        $this->assertCount(2, $folders->all());
        $this->assertCount(2, $files->all(new LibraryFolder('Spanish')));
    }

    public function test_an_unreadable_library_yields_no_folders_rather_than_an_error(): void
    {
        // A page that renders the sidebar must not 500 because the mount vanished.
        config()->set('filesystems.disks.music.root', '/nonexistent/path/'.uniqid());

        $this->actingAs(User::factory()->create());

        $this->assertSame([], app(FolderService::class)->all());
    }
}
