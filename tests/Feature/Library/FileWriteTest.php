<?php

namespace Tests\Feature\Library;

use App\Exceptions\LibraryException;
use App\Models\User;
use App\Services\Library\FileService;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileWriteTest extends TestCase
{
    use RefreshDatabase;

    private function library(): FileService
    {
        $disk = Storage::fake('music');

        $disk->makeDirectory('Spanish');
        $disk->makeDirectory('Folk');
        $disk->put('Spanish/song.flac', 'audio');

        $this->actingAs(User::factory()->admin()->create());

        return app(FileService::class);
    }

    private function file(string $folder = 'Spanish', string $name = 'song.flac'): LibraryFile
    {
        return new LibraryFile(new LibraryFolder($folder), $name);
    }

    public function test_it_moves_a_file_and_refreshes_both_folder_listings(): void
    {
        $files = $this->library();

        // Warm both listings, so a move that fails to invalidate would show the file
        // in two places at once.
        $files->all(new LibraryFolder('Spanish'));
        $files->all(new LibraryFolder('Folk'));

        $moved = $files->move($this->file(), new LibraryFolder('Folk'));

        $this->assertSame('Folk/song.flac', $moved->path());
        Storage::disk('music')->assertExists('Folk/song.flac');
        Storage::disk('music')->assertMissing('Spanish/song.flac');

        $this->assertCount(0, $files->all(new LibraryFolder('Spanish')));
        $this->assertCount(1, $files->all(new LibraryFolder('Folk')));
    }

    public function test_it_never_overwrites_a_file_at_the_destination(): void
    {
        // Two different tracks can easily share a filename across folders, and
        // clobbering one silently is unrecoverable.
        $files = $this->library();
        Storage::disk('music')->put('Folk/song.flac', 'a different track');

        try {
            $files->move($this->file(), new LibraryFolder('Folk'));
            $this->fail('The move should have been refused.');
        } catch (LibraryException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        // Both files survive, untouched.
        $this->assertSame('audio', Storage::disk('music')->get('Spanish/song.flac'));
        $this->assertSame('a different track', Storage::disk('music')->get('Folk/song.flac'));
    }

    public function test_moving_into_the_same_folder_is_refused(): void
    {
        $files = $this->library();

        $this->expectException(LibraryException::class);

        $files->move($this->file(), new LibraryFolder('Spanish'));
    }

    public function test_moving_a_file_that_has_gone_is_reported_not_fatal(): void
    {
        $files = $this->library();

        $this->expectException(LibraryException::class);

        $files->move($this->file(name: 'ghost.flac'), new LibraryFolder('Folk'));
    }

    public function test_it_renames_a_file_in_place(): void
    {
        $files = $this->library();

        $renamed = $files->rename($this->file(), 'Artist - Title.flac');

        $this->assertSame('Spanish/Artist - Title.flac', $renamed->path());
        Storage::disk('music')->assertExists('Spanish/Artist - Title.flac');
        Storage::disk('music')->assertMissing('Spanish/song.flac');
    }

    public function test_renaming_onto_an_existing_filename_is_refused(): void
    {
        $files = $this->library();
        Storage::disk('music')->put('Spanish/taken.flac', 'x');

        $this->expectException(LibraryException::class);

        $files->rename($this->file(), 'taken.flac');
    }

    public function test_renaming_rejects_a_name_that_could_escape_the_folder(): void
    {
        $files = $this->library();

        foreach (['../escape.flac', 'sub/dir.flac', '', '.hidden.flac'] as $attempt) {
            try {
                $files->rename($this->file(), $attempt);
                $this->fail("Renaming to [{$attempt}] should have been refused.");
            } catch (LibraryException) {
                $this->addToAssertionCount(1);
            }
        }

        Storage::disk('music')->assertExists('Spanish/song.flac');
    }

    public function test_it_deletes_a_file_and_refreshes_the_listing(): void
    {
        $files = $this->library();

        $files->all(new LibraryFolder('Spanish'));

        $files->delete($this->file());

        Storage::disk('music')->assertMissing('Spanish/song.flac');
        $this->assertCount(0, $files->all(new LibraryFolder('Spanish')));
    }

    public function test_a_held_lock_reports_the_file_as_busy_rather_than_corrupting_it(): void
    {
        // Metadata writes rewrite a whole FLAC, so a concurrent move or delete on the
        // same path can leave a half-written file. The lock is what prevents that; this
        // asserts the second writer is told to wait instead of proceeding.
        $files = $this->library();

        $lock = Cache::lock('minizo:file:spanish/song.flac', 15);
        $this->assertTrue($lock->get(), 'could not acquire the lock for the test');

        try {
            $files->delete($this->file());
            $this->fail('The delete should have been refused while locked.');
        } catch (LibraryException $e) {
            $this->assertStringContainsString('being modified', $e->getMessage());
        } finally {
            $lock->release();
        }

        // Crucially, the file is still intact.
        Storage::disk('music')->assertExists('Spanish/song.flac');
    }

    public function test_the_lock_is_released_after_a_successful_write(): void
    {
        // A lock leaked on the happy path would make the file permanently unwritable
        // until the 15s TTL expired.
        $files = $this->library();

        $files->rename($this->file(), 'first.flac');

        $lock = Cache::lock('minizo:file:spanish/first.flac', 5);
        $this->assertTrue($lock->get(), 'the lock was not released after the write');
        $lock->release();
    }

    public function test_the_lock_is_released_even_when_the_write_fails(): void
    {
        $files = $this->library();
        Storage::disk('music')->put('Folk/song.flac', 'collision');

        try {
            $files->move($this->file(), new LibraryFolder('Folk'));
        } catch (LibraryException) {
            // expected
        }

        $lock = Cache::lock('minizo:file:spanish/song.flac', 5);
        $this->assertTrue($lock->get(), 'the lock leaked after a failed write');
        $lock->release();
    }
}
