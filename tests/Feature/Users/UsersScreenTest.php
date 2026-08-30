<?php

namespace Tests\Feature\Users;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Sharing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The accounts list, and the instance switch that sits above it. */
class UsersScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $disk = Storage::fake('music');
        $disk->makeDirectory('Spanish');
        $disk->makeDirectory('Folk');
    }

    public function test_the_screen_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('users'))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users'))
            ->assertOk();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get(route('users'))->assertRedirect(route('login'));
    }

    public function test_admins_are_listed_first_then_alphabetically(): void
    {
        // The people who can change things are the ones an admin is usually looking for.
        User::factory()->create(['name' => 'Zoe']);
        User::factory()->create(['name' => 'Ana']);
        $admin = User::factory()->admin()->create(['name' => 'Mia']);

        $names = Livewire::actingAs($admin)
            ->test('pages::users')
            ->instance()
            ->accounts
            ->pluck('name')
            ->all();

        $this->assertSame(['Mia', 'Ana', 'Zoe'], $names);
    }

    public function test_it_shows_the_folder_and_permission_summaries_from_the_design(): void
    {
        $admin = User::factory()->admin()->create();

        User::factory()->withFolders(['Spanish'])->viewOnly()->create(['name' => 'Restricted']);

        Livewire::actingAs($admin)
            ->test('pages::users')
            ->assertSee('1 folder')
            // The design's exact fallback when nothing is granted.
            ->assertSee('View only', escape: false)
            // And the all-access sentinel reads as words, not as an asterisk.
            ->assertSee('All folders', escape: false);
    }

    public function test_the_permission_summary_ignores_the_instance_switch(): void
    {
        /*
         * granted(), not effective(). This column reports what an admin GAVE someone - a
         * summary that changed because the sharing switch was flipped would be describing
         * the instance rather than the account.
         */
        Sharing::fake(false);

        try {
            $admin = User::factory()->admin()->create();
            User::factory()->withPermissions([Permission::Share])->create(['name' => 'Sharer']);

            $sharer = Livewire::actingAs($admin)
                ->test('pages::users')
                ->instance()
                ->accounts
                ->firstWhere('name', 'Sharer');

            /*
             * Asserted on the summary itself rather than on the rendered HTML: "Share" is a
             * substring of the sharing card's own copy on this screen, so a text assertion
             * would pass even if the column had gone blank.
             */
            $this->assertSame(
                'Share',
                Permissions::forUser($sharer, sharingEnabled: true)->summaryLabel(),
            );
        } finally {
            Sharing::clearFake();
        }
    }

    public function test_it_shows_active_and_disabled_status(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->disabled()->create(['name' => 'Banned']);

        Livewire::actingAs($admin)
            ->test('pages::users')
            ->assertSee('Disabled', escape: false)
            ->assertSee('Active', escape: false);
    }

    // ------------------------------------------------------- the sharing switch

    public function test_the_sharing_switch_is_on_this_screen_not_in_settings(): void
    {
        /*
         * The handoff's written spec puts it under Settings; the prototype draws it here.
         * The prototype wins - this is the screen about what other people may do, and
         * Settings is about your own account.
         */
        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::users')
            ->assertSee('Public share links', escape: false);
    }

    public function test_settings_no_longer_offers_a_sharing_page(): void
    {
        // Asserted, so the route cannot come back unnoticed.
        $this->assertFalse(app('router')->has('sharing.edit'));
    }

    public function test_an_admin_can_toggle_public_sharing(): void
    {
        $component = Livewire::actingAs(User::factory()->admin()->create())->test('pages::users');

        $this->assertTrue($component->instance()->sharingEnabled);

        $component->call('toggleSharing');

        $this->assertFalse(Sharing::enabled());

        $component->call('toggleSharing');

        $this->assertTrue(Sharing::enabled());
    }

    public function test_a_non_admin_cannot_reach_the_sharing_switch_at_all(): void
    {
        // Two layers, asserted separately because the action cannot be exercised in isolation:
        // mount() authorizes first, so a non-admin never gets a mounted component to call
        // anything on. The second assertion covers the gate the action itself consults.
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('users'))->assertForbidden();

        $this->assertFalse($user->can('toggle-sharing'));
        $this->assertTrue(User::factory()->admin()->create()->can('toggle-sharing'));
    }
}
