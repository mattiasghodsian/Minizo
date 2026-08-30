<?php

namespace Tests\Feature\Authorization;

use App\Enums\Permission;
use App\Models\User;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use App\Support\Sharing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Sharing::clearFake();

        parent::tearDown();
    }

    private function file(string $folder = 'Spanish', string $name = 'Emilia - GTA.flac'): LibraryFile
    {
        return new LibraryFile(new LibraryFolder($folder), $name);
    }

    public function test_folder_access_gates_viewing(): void
    {
        $user = User::factory()->withFolders(['Spanish'])->create();

        $this->assertTrue($user->can('view', new LibraryFolder('Spanish')));
        $this->assertFalse($user->can('view', new LibraryFolder('Folk')));
    }

    public function test_an_admin_is_not_exempt_from_folder_access(): void
    {
        // There is no Gate::before admin bypass. "May administer the instance" and
        // "may see this music" are different questions, and the design shows
        // folder-access chips for admins too.
        $admin = User::factory()->admin()->withFolders(['Spanish'])->create();

        $this->assertTrue($admin->can('view', new LibraryFolder('Spanish')));
        $this->assertFalse($admin->can('view', new LibraryFolder('Folk')));
    }

    public function test_every_file_ability_requires_folder_access_even_with_the_permission(): void
    {
        // A permission is meaningless on a folder you cannot see. If this fails,
        // an ability has been written without composing view().
        $user = User::factory()->withFolders(['Spanish'])->create();
        $unreachable = $this->file('Folk');

        foreach (['view', 'editMetadata', 'download', 'delete', 'share'] as $ability) {
            $this->assertFalse(
                $user->can($ability, $unreachable),
                "{$ability} leaked past folder access",
            );
        }
    }

    public function test_each_file_ability_requires_its_own_permission(): void
    {
        $abilities = [
            'editMetadata' => Permission::Edit,
            'download' => Permission::Download,
            'delete' => Permission::Delete,
            'share' => Permission::Share,
        ];

        foreach ($abilities as $ability => $permission) {
            $granted = User::factory()->withPermissions([$permission])->create();
            $denied = User::factory()->without([$permission])->create();

            $this->assertTrue($granted->can($ability, $this->file()), "{$ability} denied when granted");
            $this->assertFalse($denied->can($ability, $this->file()), "{$ability} allowed when not granted");
        }
    }

    public function test_moving_requires_access_to_both_source_and_destination(): void
    {
        // The destination is part of the ability signature so it cannot be
        // forgotten. Moving a file into a folder you cannot see hides it; moving
        // one out of a folder you cannot see exfiltrates it.
        $user = User::factory()->withFolders(['Spanish', 'Folk'])->create();

        $this->assertTrue($user->can('move', [$this->file('Spanish'), new LibraryFolder('Folk')]));

        $this->assertFalse(
            $user->can('move', [$this->file('Spanish'), new LibraryFolder('GameBT')]),
            'move into an inaccessible destination must be refused',
        );

        $this->assertFalse(
            $user->can('move', [$this->file('GameBT'), new LibraryFolder('Spanish')]),
            'move out of an inaccessible source must be refused',
        );
    }

    public function test_moving_requires_the_move_permission(): void
    {
        $user = User::factory()->without([Permission::Move])->create();

        $this->assertFalse($user->can('move', [$this->file(), new LibraryFolder('Folk')]));
    }

    public function test_folder_management_is_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $folder = new LibraryFolder('Spanish');

        foreach (['create', 'rename', 'delete'] as $ability) {
            $this->assertTrue($admin->can($ability, $ability === 'create' ? LibraryFolder::class : $folder));
        }

        $this->assertFalse($user->can('create', LibraryFolder::class));
        $this->assertFalse($user->can('rename', $folder));
        $this->assertFalse($user->can('delete', $folder));
    }

    public function test_the_global_sharing_switch_refuses_sharing_for_everyone(): void
    {
        Sharing::fake(false);

        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->assertFalse($user->can('share', $this->file()), 'user share must be refused');
        $this->assertFalse($admin->can('share', $this->file()), 'admin share must be refused too');
        $this->assertFalse($admin->can('share', new LibraryFolder('Spanish')));

        // Still granted on the account - so the UI renders it dimmed rather than
        // hiding it, which is how the user learns why it does nothing.
        $this->assertTrue($user->permissions()->dimmed(Permission::Share));
    }

    public function test_the_sharing_switch_does_not_affect_other_abilities(): void
    {
        Sharing::fake(false);

        $user = User::factory()->create();

        $this->assertTrue($user->can('download', $this->file()));
        $this->assertTrue($user->can('editMetadata', $this->file()));
    }

    public function test_archive_download_requires_download_permission_and_access(): void
    {
        $granted = User::factory()->withFolders(['Spanish'])->create();
        $this->assertTrue($granted->can('downloadArchive', new LibraryFolder('Spanish')));
        $this->assertFalse($granted->can('downloadArchive', new LibraryFolder('Folk')));

        $denied = User::factory()->without([Permission::Download])->create();
        $this->assertFalse($denied->can('downloadArchive', new LibraryFolder('Spanish')));
    }

    public function test_a_user_with_no_folders_can_do_nothing(): void
    {
        // The production default for a new account. It must be inert, not open.
        $user = User::factory()->withoutFolders()->create();

        $this->assertFalse($user->can('view', new LibraryFolder('Spanish')));
        $this->assertFalse($user->can('view', $this->file()));
        $this->assertFalse($user->can('download', $this->file()));
    }
}
