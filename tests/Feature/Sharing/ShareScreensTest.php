<?php

namespace Tests\Feature\Sharing;

use App\Enums\Permission;
use App\Enums\ShareExpiry;
use App\Models\Share;
use App\Models\User;
use App\Support\Settings;
use App\Support\Sharing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The three authenticated surfaces: the Share modal, the Share links screen, and the instance switch that gates them. */
class ShareScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $disk = Storage::fake('music');
        $disk->makeDirectory('Spanish');
        $disk->makeDirectory('Folk');
        $disk->put('Spanish/song.flac', 'audio');
        $disk->put('Folk/tune.flac', 'audio');
    }

    // ------------------------------------------------------------- share modal

    public function test_the_modal_generates_a_link_for_a_folder(): void
    {
        $component = Livewire::actingAs(User::factory()->create())
            ->test('pages::files.share-modal')
            ->call('openFolder', 'Spanish')
            ->set('expiry', ShareExpiry::SixHours->value)
            ->call('generate')
            ->assertHasNoErrors();

        $share = Share::sole();

        $this->assertSame('Spanish', $share->folder);
        $this->assertSame($share->token, $component->get('token'));

        // The Share links screen is a sibling and owns its own listing.
        $component->assertDispatched('shares-updated');
    }

    public function test_the_modal_generates_a_link_for_a_track(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::files.share-modal')
            ->call('openFile', 'Spanish', 'song.flac')
            ->call('generate')
            ->assertHasNoErrors();

        $share = Share::sole();

        $this->assertSame('song.flac', $share->filename);
        $this->assertSame('song', $share->name);
    }

    public function test_the_modal_shows_the_url_only_after_generating(): void
    {
        // The design's two states: a form, then a URL with a Copy button.
        $component = Livewire::actingAs(User::factory()->create())
            ->test('pages::files.share-modal')
            ->call('openFolder', 'Spanish');

        $component->assertSee('Generate link', escape: false);

        $component->call('generate');

        $component->assertSee('Open the public page', escape: false)
            ->assertSee(Share::sole()->token);
    }

    public function test_a_second_share_of_the_same_folder_gets_its_own_token(): void
    {
        // Two links to one folder is legitimate - different recipients, different
        // lifetimes - and each has to be revocable independently.
        $user = User::factory()->create();

        $first = Livewire::actingAs($user)->test('pages::files.share-modal')
            ->call('openFolder', 'Spanish')->call('generate')->get('token');

        $second = Livewire::actingAs($user)->test('pages::files.share-modal')
            ->call('openFolder', 'Spanish')->call('generate')->get('token');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, Share::count());
    }

    public function test_the_modal_reports_an_empty_folder_on_the_field(): void
    {
        Storage::disk('music')->makeDirectory('Empty');

        Livewire::actingAs(User::factory()->create())
            ->test('pages::files.share-modal')
            ->call('openFolder', 'Empty')
            ->call('generate')
            ->assertHasErrors('expiry');

        $this->assertSame(0, Share::count());
    }

    public function test_a_user_without_the_share_permission_cannot_open_the_modal(): void
    {
        Livewire::actingAs(User::factory()->without([Permission::Share])->create())
            ->test('pages::files.share-modal')
            ->call('openFolder', 'Spanish')
            ->assertForbidden();
    }

    public function test_a_user_cannot_share_a_folder_they_cannot_see(): void
    {
        Livewire::actingAs(User::factory()->withFolders(['Folk'])->create())
            ->test('pages::files.share-modal')
            ->call('openFolder', 'Spanish')
            ->assertForbidden();
    }

    public function test_a_crafted_filename_cannot_be_shared(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::files.share-modal')
            ->call('openFile', 'Spanish', '../../.env')
            ->assertNotFound();
    }

    // ------------------------------------------------------- share links screen

    public function test_the_screen_lists_every_link_with_live_ones_first(): void
    {
        /*
         * Dead rows are kept for the retention window and render at half opacity, but they
         * must not push a live link off the top - the live ones are the only ones anyone
         * can still act on.
         */
        $user = User::factory()->create();

        $dead = Share::factory()->folder('Spanish')->expired()->create([
            'user_id' => $user->id, 'created_at' => now(),
        ]);
        $live = Share::factory()->folder('Folk')->create([
            'user_id' => $user->id, 'created_at' => now()->subDay(),
        ]);

        $ids = Livewire::actingAs($user)
            ->test('pages::shares')
            ->instance()
            ->links
            ->pluck('id')
            ->all();

        $this->assertSame([$live->id, $dead->id], $ids);
    }

    public function test_the_active_count_counts_only_live_links(): void
    {
        $user = User::factory()->create();

        Share::factory()->count(2)->create(['user_id' => $user->id]);
        Share::factory()->expired()->create(['user_id' => $user->id]);
        Share::factory()->revoked()->create(['user_id' => $user->id]);

        $this->assertSame(
            2,
            Livewire::actingAs($user)->test('pages::shares')->instance()->activeCount,
        );
    }

    public function test_the_owner_pills_list_only_accounts_that_have_shared_something(): void
    {
        // A pill per empty account would be a row of dead ends. Admin-only, because the
        // pills are a cross-user filter and a non-admin's list is only ever their own.
        $admin = User::factory()->admin()->create(['name' => 'Zoe']);
        $sharer = User::factory()->create(['name' => 'Ana']);
        User::factory()->create(['name' => 'Nobody']);

        Share::factory()->create(['user_id' => $sharer->id]);

        $owners = Livewire::actingAs($admin)->test('pages::shares')->instance()->owners;

        $this->assertSame(['Ana'], $owners->pluck('name')->all());
    }

    public function test_filtering_by_owner_narrows_the_list(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        $theirs = Share::factory()->create(['user_id' => $other->id]);
        Share::factory()->create(['user_id' => $admin->id]);

        $ids = Livewire::actingAs($admin)
            ->test('pages::shares')
            ->call('filterBy', $other->id)
            ->instance()
            ->links
            ->pluck('id')
            ->all();

        $this->assertSame([$theirs->id], $ids);
    }

    // ------------------------------------------------- the link IS the capability

    public function test_a_non_admin_sees_only_their_own_links(): void
    {
        /*
         * The screen renders the working URL with a copy button, and that URL bypasses
         * folder_access by design - a stranger holding it has no account at all. So
         * showing someone else's link would hand out a folder this user was never
         * granted, and the grant list would mean nothing.
         */
        $viewer = User::factory()->withFolders(['Spanish'])->create();
        $other = User::factory()->create();

        $mine = Share::factory()->folder('Spanish')->create(['user_id' => $viewer->id]);
        $theirs = Share::factory()->folder('Unprocessed')->create(['user_id' => $other->id]);

        $ids = Livewire::actingAs($viewer)->test('pages::shares')->instance()->links->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_an_admin_still_sees_every_link(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        $mine = Share::factory()->create(['user_id' => $admin->id]);
        $theirs = Share::factory()->create(['user_id' => $other->id]);

        $ids = Livewire::actingAs($admin)->test('pages::shares')->instance()->links->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], $ids);
    }

    public function test_a_non_admin_gets_no_owner_pills_and_cannot_filter_by_owner(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create();

        Share::factory()->create(['user_id' => $other->id]);

        $component = Livewire::actingAs($viewer)->test('pages::shares');

        $this->assertTrue($component->instance()->owners->isEmpty());

        // Hidden in the markup, but the action is still callable over the wire.
        $component->call('filterBy', $other->id)->assertForbidden();
    }

    public function test_the_active_count_counts_only_what_the_viewer_can_see(): void
    {
        // Otherwise the badge reports a number the table below cannot account for.
        $viewer = User::factory()->create();
        $other = User::factory()->create();

        Share::factory()->create(['user_id' => $viewer->id]);
        Share::factory()->count(3)->create(['user_id' => $other->id]);

        $this->assertSame(
            1,
            Livewire::actingAs($viewer)->test('pages::shares')->instance()->activeCount,
        );
    }

    public function test_the_owner_can_expire_their_own_link(): void
    {
        $user = User::factory()->create();
        $share = Share::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)->test('pages::shares')->call('revoke', $share->id);

        $this->assertNotNull($share->fresh()->revoked_at);
    }

    public function test_an_admin_can_expire_someone_elses_link(): void
    {
        // The screen's stated purpose is to kill links; an admin who cannot is not
        // administering anything.
        $share = Share::factory()->create(['user_id' => User::factory()->create()->id]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::shares')
            ->call('revoke', $share->id);

        $this->assertNotNull($share->fresh()->revoked_at);
    }

    public function test_a_non_admin_cannot_expire_someone_elses_link(): void
    {
        // Seeing that someone shared a folder is auditing; unpublishing it on their behalf
        // is not.
        $share = Share::factory()->create(['user_id' => User::factory()->create()->id]);

        Livewire::actingAs(User::factory()->create())
            ->test('pages::shares')
            ->call('revoke', $share->id)
            ->assertForbidden();

        $this->assertNull($share->fresh()->revoked_at);
    }

    public function test_a_dead_row_can_be_removed_from_the_audit_list(): void
    {
        $user = User::factory()->create();
        $share = Share::factory()->expired()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)->test('pages::shares')->call('forget', $share->id);

        $this->assertDatabaseMissing('shares', ['id' => $share->id]);
    }

    public function test_a_live_row_cannot_be_removed(): void
    {
        /*
         * Deleting a live link would take it down while erasing the record that it ever
         * existed, which defeats the point of an audit screen. Expire it first.
         */
        $user = User::factory()->create();
        $share = Share::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)->test('pages::shares')->call('forget', $share->id)->assertForbidden();

        $this->assertDatabaseHas('shares', ['id' => $share->id]);
    }

    public function test_a_user_without_the_share_permission_cannot_open_the_screen(): void
    {
        $this->actingAs(User::factory()->without([Permission::Share])->create())
            ->get(route('shares'))
            ->assertForbidden();
    }

    public function test_the_screen_stays_reachable_when_the_instance_switch_is_off(): void
    {
        /*
         * granted(), not effective(). Turning sharing off is exactly when someone wants to
         * see which links are still live - hiding the audit tool then would be backwards.
         */
        Sharing::fake(false);

        try {
            $this->actingAs(User::factory()->create())
                ->get(route('shares'))
                ->assertOk();
        } finally {
            Sharing::clearFake();
        }
    }

    // ------------------------------------------------------------ the kill switch
    //
    // The switch itself lives on the Users screen and UsersScreenTest covers its own
    // behaviour. What is tested here is what flipping it does to sharing.

    private function disableSharing(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::users')
            ->call('toggleSharing');
    }

    public function test_the_switch_is_persisted_not_just_held_in_memory(): void
    {
        $this->disableSharing();

        $this->assertFalse(Sharing::enabled());
        $this->assertDatabaseHas('settings', ['key' => Settings::SHARING_ENABLED, 'value' => '0']);
    }

    public function test_turning_the_switch_off_does_not_revoke_existing_links(): void
    {
        // The half people assume works the other way. "Stop offering this feature" and
        // "un-publish everything already shared" are different decisions.
        $share = Share::factory()->folder('Spanish')->create();

        $this->disableSharing();

        $share->refresh();

        $this->assertNull($share->revoked_at);
        $this->assertTrue($share->isLive());

        // And the public page still serves it.
        $this->get(route('share.show', $share->token))->assertOk();
    }

    public function test_the_switch_stops_new_links_being_created(): void
    {
        $this->disableSharing();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::files.share-modal')
            ->call('openFolder', 'Spanish')
            ->assertForbidden();
    }

    public function test_the_switch_survives_a_fresh_read(): void
    {
        // The value is cached forever and invalidated on write; a stale cache would make
        // the toggle appear to do nothing until something else cleared it.
        Sharing::set(false);

        $this->assertFalse(Sharing::enabled());

        Sharing::set(true);

        $this->assertTrue(Sharing::enabled());
    }

    public function test_the_row_menu_dims_the_share_action_rather_than_hiding_it(): void
    {
        /*
         * The design's three-state rule: not granted -> absent; granted but globally off
         * -> visible at 35% and inert; granted and on -> live. Dimming is what tells a
         * user the action exists and an admin switched it off.
         */
        Sharing::set(false);

        $html = Livewire::actingAs(User::factory()->create())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        $this->assertStringContainsString('Share', $html);
        $this->assertStringContainsString('opacity-35', $html);
    }
}
