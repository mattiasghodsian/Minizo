<?php

namespace Tests\Feature\Library;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The Livewire side of the write path: the folder manager and the Files screen's move/delete actions, including that each one refuses an unauthorized caller. */
class LibraryModalsTest extends TestCase
{
    use RefreshDatabase;

    private function library(): void
    {
        $disk = Storage::fake('music');

        $disk->makeDirectory('Spanish');
        $disk->makeDirectory('Folk');
        $disk->put('Spanish/song.flac', 'audio');
    }

    // ---------------------------------------------------------------- folders

    public function test_an_admin_can_create_a_folder(): void
    {
        $this->library();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::folders.manager')
            ->set('name', 'Jazz')
            ->call('create')
            ->assertHasNoErrors();

        Storage::disk('music')->assertExists('Jazz');
    }

    public function test_a_duplicate_name_is_reported_on_the_field(): void
    {
        // A user-fixable outcome belongs on the input, not on an error page.
        $this->library();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::folders.manager')
            ->set('name', 'spanish')
            ->call('create')
            ->assertHasErrors('name');
    }

    public function test_the_name_error_is_computed_before_submitting(): void
    {
        // Powers the live "/music/<name>" preview and the disabled submit button.
        $this->library();

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::folders.manager');

        $component->set('name', 'Spanish');
        $this->assertNotNull($component->instance()->nameError());

        $component->set('name', 'a/b');
        $this->assertNotNull($component->instance()->nameError());

        $component->set('name', 'Brand New');
        $this->assertNull($component->instance()->nameError());
    }

    public function test_a_non_admin_cannot_create_a_folder(): void
    {
        // Folder management is admin-only and NOT a permission: it changes the library
        // for everyone, including which names other users' grants refer to.
        $this->library();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::folders.manager')
            ->set('name', 'Sneaky')
            ->call('create')
            ->assertForbidden();

        Storage::disk('music')->assertMissing('Sneaky');
    }

    public function test_an_admin_can_rename_a_folder(): void
    {
        $this->library();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::folders.manager')
            ->call('openRename', 'Folk')
            ->set('name', 'Folk Music')
            ->call('rename')
            ->assertHasNoErrors();

        Storage::disk('music')->assertExists('Folk Music');
        Storage::disk('music')->assertMissing('Folk');
    }

    public function test_a_non_admin_cannot_rename_a_folder(): void
    {
        $this->library();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::folders.manager')
            ->call('openRename', 'Folk')
            ->assertForbidden();
    }

    public function test_an_admin_can_delete_a_folder(): void
    {
        $this->library();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::folders.manager')
            ->call('openDelete', 'Folk')
            ->call('delete');

        Storage::disk('music')->assertMissing('Folk');
    }

    public function test_a_non_admin_cannot_delete_a_folder(): void
    {
        $this->library();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::folders.manager')
            ->call('openDelete', 'Spanish')
            ->assertForbidden();

        Storage::disk('music')->assertExists('Spanish');
    }

    // ------------------------------------------------------------------ files

    public function test_a_file_can_be_moved_between_folders(): void
    {
        $this->library();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->call('openMove', 'song.flac')
            ->set('moveTo', 'Folk')
            ->call('move')
            ->assertHasNoErrors();

        Storage::disk('music')->assertExists('Folk/song.flac');
        Storage::disk('music')->assertMissing('Spanish/song.flac');
    }

    public function test_the_move_button_follows_the_dropdown_on_the_client(): void
    {
        $this->library();

        $html = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->call('openMove', 'song.flac')
            ->html();

        /*
         * An assertion about markup, because no ->set() test can reach this: setting the
         * property server-side drives the component down a path the browser never takes.
         *
         * A Blade :disabled is evaluated once, during render, when $moveTo is still ''. The
         * select's plain wire:model syncs the choice into $wire and sends nothing, so this
         * markup is never re-rendered - and the button stayed disabled no matter what was
         * chosen. The binding has to be client-side.
         */
        $this->assertStringContainsString('x-bind:disabled="! $wire.moveTo"', $html);
        $this->assertStringNotContainsString('disabled="disabled"', $html);
    }

    public function test_moving_with_no_destination_chosen_is_refused(): void
    {
        $this->library();

        // The button is a convenience; this is the check. Both ends of the modal have to
        // refuse a blank destination, since one is markup and can be bypassed.
        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->call('openMove', 'song.flac')
            ->call('move')
            ->assertHasErrors('moveTo');

        Storage::disk('music')->assertExists('Spanish/song.flac');
    }

    public function test_moving_without_the_move_permission_is_refused(): void
    {
        $this->library();

        Livewire::actingAs(User::factory()->without([Permission::Move])->create())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->call('openMove', 'song.flac')
            ->assertForbidden();

        Storage::disk('music')->assertExists('Spanish/song.flac');
    }

    public function test_move_targets_exclude_the_current_folder_and_unreachable_ones(): void
    {
        $this->library();
        Storage::disk('music')->makeDirectory('Hidden');

        $user = User::factory()->withFolders(['Spanish', 'Folk'])->create();

        $targets = Livewire::actingAs($user)
            ->test('pages::files', ['directory' => 'Spanish'])
            ->instance()
            ->moveTargets();

        $this->assertSame(['Folk'], $targets);
    }

    public function test_moving_into_a_folder_the_user_cannot_see_is_refused(): void
    {
        // The destination is part of the ability signature, so it cannot be skipped.
        $this->library();

        $user = User::factory()->withFolders(['Spanish'])->create();

        Livewire::actingAs($user)
            ->test('pages::files', ['directory' => 'Spanish'])
            ->call('openMove', 'song.flac')
            ->set('moveTo', 'Folk')
            ->call('move')
            ->assertForbidden();

        Storage::disk('music')->assertExists('Spanish/song.flac');
    }

    public function test_a_file_can_be_deleted(): void
    {
        $this->library();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->call('openDelete', 'song.flac')
            ->call('delete');

        Storage::disk('music')->assertMissing('Spanish/song.flac');
    }

    public function test_deleting_without_the_delete_permission_is_refused(): void
    {
        $this->library();

        Livewire::actingAs(User::factory()->without([Permission::Delete])->create())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->call('openDelete', 'song.flac')
            ->assertForbidden();

        Storage::disk('music')->assertExists('Spanish/song.flac');
    }

    public function test_acting_on_a_file_that_has_gone_is_a_404_not_a_crash(): void
    {
        $this->library();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->call('openDelete', 'never-existed.flac')
            ->assertNotFound();
    }

    public function test_a_crafted_filename_cannot_escape_the_folder(): void
    {
        // Only a filename crosses the wire, and it is re-resolved against the folder's
        // real contents - so a traversal string simply resolves to nothing.
        $this->library();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->call('openDelete', '../../.env')
            ->assertNotFound();
    }
}
