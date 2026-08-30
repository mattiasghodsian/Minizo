<?php

namespace Tests\Feature\Library;

use App\Exceptions\LibraryException;
use App\Models\User;
use App\Services\Library\FolderService;
use App\Support\LibraryFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FolderWriteTest extends TestCase
{
    use RefreshDatabase;

    private function library(array $folders = ['Spanish']): FolderService
    {
        $disk = Storage::fake('music');

        foreach ($folders as $folder) {
            $disk->makeDirectory($folder);
        }

        $this->actingAs(User::factory()->admin()->create());

        return app(FolderService::class);
    }

    public function test_it_creates_a_folder_and_the_listing_updates_immediately(): void
    {
        $folders = $this->library();

        // Warm the cache first - a create that does not invalidate it would appear to
        // do nothing, which is the failure mode worth guarding.
        $folders->names();

        $folder = $folders->create('Folk');

        $this->assertSame('Folk', $folder->name);
        Storage::disk('music')->assertExists('Folk');
        $this->assertSame(['Folk', 'Spanish'], $folders->names());
    }

    public function test_it_refuses_a_duplicate_name_case_insensitively(): void
    {
        // A Windows host would see "spanish" and "Spanish" as one directory while the
        // container would not. Refusing both spellings is the only safe answer.
        $folders = $this->library(['Spanish']);

        foreach (['Spanish', 'spanish', 'SPANISH', '  Spanish  '] as $attempt) {
            try {
                $folders->create($attempt);
                $this->fail("Creating [{$attempt}] should have been refused.");
            } catch (LibraryException $e) {
                $this->assertStringContainsString('already exists', $e->getMessage());
            }
        }
    }

    public function test_it_refuses_names_that_could_escape_the_library(): void
    {
        $folders = $this->library();

        foreach (['../etc', 'a/b', 'a\\b', '', '   ', '.hidden', '.', '..'] as $attempt) {
            try {
                $folders->create($attempt);
                $this->fail("Creating [{$attempt}] should have been refused.");
            } catch (LibraryException) {
                $this->addToAssertionCount(1);
            }
        }

        // Nothing was created outside the one folder we started with.
        $this->assertSame(['Spanish'], $folders->names());
    }

    public function test_renaming_moves_the_directory_and_refreshes_the_listing(): void
    {
        $folders = $this->library(['Folk', 'Spanish']);
        Storage::disk('music')->put('Folk/song.flac', 'x');

        $renamed = $folders->rename(new LibraryFolder('Folk'), 'Folk Music');

        $this->assertSame('Folk Music', $renamed->name);
        Storage::disk('music')->assertExists('Folk Music/song.flac');
        Storage::disk('music')->assertMissing('Folk/song.flac');
        $this->assertSame(['Folk Music', 'Spanish'], $folders->names());
    }

    public function test_renaming_follows_through_to_every_users_folder_access(): void
    {
        // The fan-out. Folders have no database rows, so grants store the NAME - and
        // without this a rename silently revokes access.
        $folders = $this->library(['Folk']);

        $granted = User::factory()->withFolders(['Folk', 'Spanish'])->create();
        $unrelated = User::factory()->withFolders(['Spanish'])->create();
        $allAccess = User::factory()->create(); // holds the "*" sentinel

        $folders->rename(new LibraryFolder('Folk'), 'Folk Music');

        $this->assertTrue($granted->fresh()->folderAccess()->allows('Folk Music'));
        $this->assertFalse($granted->fresh()->folderAccess()->allows('Folk'));

        // Untouched users keep exactly what they had.
        $this->assertSame(['Spanish'], $unrelated->fresh()->folder_access);

        // A "*" user needs no rewriting - that is the point of the sentinel.
        $this->assertTrue($allAccess->fresh()->folderAccess()->allowsAll());
    }

    public function test_renaming_to_a_different_casing_is_allowed(): void
    {
        $folders = $this->library(['folk']);

        $renamed = $folders->rename(new LibraryFolder('folk'), 'Folk');

        $this->assertSame('Folk', $renamed->name);
    }

    public function test_renaming_onto_an_existing_folder_is_refused(): void
    {
        $folders = $this->library(['Spanish', 'Folk']);

        $this->expectException(LibraryException::class);

        $folders->rename(new LibraryFolder('Folk'), 'Spanish');
    }

    public function test_deleting_removes_the_directory_and_its_contents(): void
    {
        $folders = $this->library(['Folk', 'Spanish']);
        Storage::disk('music')->put('Folk/song.flac', 'x');

        $folders->delete(new LibraryFolder('Folk'));

        Storage::disk('music')->assertMissing('Folk/song.flac');
        $this->assertSame(['Spanish'], $folders->names());
    }

    public function test_deleting_revokes_the_folder_from_every_grant_list(): void
    {
        // Otherwise a deleted folder lingers in folder_access and silently grants
        // access again if a folder of the same name is created later.
        $folders = $this->library(['Folk']);

        $user = User::factory()->withFolders(['Folk', 'Spanish'])->create();

        $folders->delete(new LibraryFolder('Folk'));

        $this->assertSame(['Spanish'], $user->fresh()->folder_access);
    }

    public function test_operating_on_a_folder_that_has_gone_is_reported_not_fatal(): void
    {
        $folders = $this->library();

        $this->expectException(LibraryException::class);

        $folders->rename(new LibraryFolder('NeverExisted'), 'Whatever');
    }
}
