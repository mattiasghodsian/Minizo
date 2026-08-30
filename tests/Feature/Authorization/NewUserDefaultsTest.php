<?php

namespace Tests\Feature\Authorization;

use App\Actions\Fortify\CreateNewUser;
use App\Enums\Role;
use App\Models\User;
use App\Support\NewUserDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewUserDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_account_on_a_fresh_instance_becomes_the_admin(): void
    {
        // Without this a new deployment is unusable: registration is off by
        // default and seeding is dev-only, so there would be no user and no way to
        // create one short of shell access.
        $this->assertSame(0, User::count());

        $first = User::create([
            'name' => 'First',
            'email' => 'first@minizo.test',
            'password' => 'password',
        ]);

        NewUserDefaults::apply($first);

        $this->assertTrue($first->fresh()->isAdmin());
        $this->assertTrue($first->fresh()->folderAccess()->allowsAll());
        $this->assertNotSame('View only', $first->fresh()->permissions()->summaryLabel());
    }

    public function test_every_later_account_starts_with_nothing(): void
    {
        User::factory()->admin()->create();

        $second = User::create([
            'name' => 'Second',
            'email' => 'second@minizo.test',
            'password' => 'password',
        ]);

        NewUserDefaults::apply($second);
        $second = $second->fresh();

        $this->assertSame(Role::User, $second->role);
        $this->assertTrue($second->is_active, 'they should be able to sign in');
        $this->assertTrue($second->folderAccess()->isEmpty(), 'but see no folders');
        $this->assertSame('View only', $second->permissions()->summaryLabel());
    }

    public function test_privilege_columns_are_not_mass_assignable(): void
    {
        // The guard that makes the whole model safe: a crafted registration
        // payload must not be able to grant itself anything.
        User::factory()->admin()->create();

        $user = User::create([
            'name' => 'Sneaky',
            'email' => 'sneaky@minizo.test',
            'password' => 'password',
            'role' => Role::Admin->value,
            'is_active' => true,
            'folder_access' => ['*'],
            'can_delete' => true,
        ]);

        $user = $user->fresh();

        $this->assertSame(Role::User, $user->role, 'role must not be mass assignable');
        $this->assertFalse($user->can_delete, 'permissions must not be mass assignable');
        $this->assertNull($user->folder_access, 'folder access must not be mass assignable');
    }

    public function test_registration_through_fortify_applies_the_defaults(): void
    {
        $this->assertSame(0, User::count());

        // Registration routes only exist when APP_REGISTER is true, so drive the
        // action directly rather than depending on the route being registered.
        $user = app(CreateNewUser::class)->create([
            'name' => 'Registered',
            'email' => 'registered@minizo.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertTrue($user->fresh()->isAdmin(), 'first registrant should be the admin');
    }

    public function test_pagination_size_is_clamped_to_configured_bounds(): void
    {
        $user = User::factory()->create();

        $user->forceFill(['pagination_size' => 100000])->save();
        $this->assertSame(config('minizo.pagination.max'), $user->fresh()->paginationSize());

        $user->forceFill(['pagination_size' => 1])->save();
        $this->assertSame(config('minizo.pagination.min'), $user->fresh()->paginationSize());

        $user->forceFill(['pagination_size' => 0])->save();
        $this->assertSame(config('minizo.pagination.default'), $user->fresh()->paginationSize());
    }
}
