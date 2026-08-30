<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use App\Support\ViewerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_administration_is_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->assertTrue($admin->can('viewAny', User::class));
        $this->assertTrue($admin->can('create', User::class));
        $this->assertTrue($admin->can('update', $other));

        $this->assertFalse($user->can('viewAny', User::class));
        $this->assertFalse($user->can('create', User::class));
        $this->assertFalse($user->can('update', $other));
    }

    public function test_an_admin_cannot_deactivate_demote_or_delete_themselves(): void
    {
        // Without this the last admin can lock every human out: registration is
        // disabled by default, so the only way back in would be shell access.
        $admin = User::factory()->admin()->create();

        $this->assertFalse($admin->can('setActive', $admin));
        $this->assertFalse($admin->can('setPermissions', $admin));
        $this->assertFalse($admin->can('delete', $admin));
    }

    public function test_an_admin_can_administer_other_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        $this->assertTrue($admin->can('setActive', $other));
        $this->assertTrue($admin->can('setPermissions', $other));
        $this->assertTrue($admin->can('delete', $other));
    }

    public function test_a_user_may_view_their_own_account_but_not_others(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->assertTrue($user->can('view', $user));
        $this->assertFalse($user->can('view', $other));
    }

    public function test_non_model_gates_are_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        foreach (['manage-users', 'manage-folders', 'toggle-sharing', 'preview-other-users'] as $ability) {
            $this->assertTrue(Gate::forUser($admin)->allows($ability), "{$ability} denied to admin");
            $this->assertFalse(Gate::forUser($user)->allows($ability), "{$ability} allowed to non-admin");
        }
    }

    public function test_previewing_another_user_narrows_visibility_but_not_authority(): void
    {
        // The admin-only "viewing as" pills. An admin previewing a view-only
        // account must still be able to act, and the folders shown must be the
        // subject's - otherwise the preview is meaningless.
        $admin = User::factory()->admin()->create();
        $restricted = User::factory()->viewOnly()->withFolders(['Spanish'])->create();

        $context = ViewerContext::previewing($admin, $restricted);

        $this->assertTrue($context->isPreview());

        // Visibility comes from the subject.
        $this->assertTrue($context->access->allows('Spanish'));
        $this->assertFalse($context->access->allows('Folk'));

        // Authority stays with the actor.
        $this->assertSame(
            $admin->permissions()->summaryLabel(),
            $context->permissions()->summaryLabel(),
        );
        $this->assertNotSame('View only', $context->permissions()->summaryLabel());
    }

    public function test_a_self_context_is_not_a_preview(): void
    {
        $user = User::factory()->withFolders(['Folk'])->create();

        $context = ViewerContext::self($user);

        $this->assertFalse($context->isPreview());
        $this->assertTrue($context->access->allows('Folk'));
    }
}
