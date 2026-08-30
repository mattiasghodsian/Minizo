<?php

namespace Tests\Feature\Users;

use App\Enums\AudioFormat;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Share;
use App\Models\User;
use App\Support\Sharing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The Manage-user modal. */
class ManageUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $disk = Storage::fake('music');

        foreach (['Spanish', 'Folk', 'Classic'] as $folder) {
            $disk->makeDirectory($folder);
        }
    }

    private function modal(?User $admin = null)
    {
        return Livewire::actingAs($admin ?? User::factory()->admin()->create())
            ->test('pages::users.manage');
    }

    // -------------------------------------------------------------------- opening

    public function test_an_admin_can_open_any_account(): void
    {
        $subject = User::factory()->create(['name' => 'Bea']);

        $this->modal()
            ->call('manage', $subject->id)
            ->assertSet('userId', $subject->id)
            ->assertSee('Bea')
            ->assertSee($subject->email);
    }

    public function test_a_non_admin_cannot_open_it(): void
    {
        $this->modal(User::factory()->create())
            ->call('manage', User::factory()->create()->id)
            ->assertForbidden();
    }

    public function test_an_unknown_account_is_a_404(): void
    {
        $this->modal()->call('manage', 999999)->assertNotFound();
    }

    // ----------------------------------------------------------------------- role

    public function test_an_admin_can_promote_and_demote_someone(): void
    {
        $subject = User::factory()->create();

        $component = $this->modal()->call('manage', $subject->id);

        $component->call('setRole', 'admin');
        $this->assertSame(Role::Admin, $subject->fresh()->role);

        $component->call('setRole', 'user');
        $this->assertSame(Role::User, $subject->fresh()->role);
    }

    public function test_an_unknown_role_falls_back_to_user_rather_than_erroring(): void
    {
        // The value crosses the wire, so it cannot be trusted to be one of two strings -
        // and the safe fallback is the less privileged one.
        $subject = User::factory()->admin()->create();

        $this->modal()->call('manage', $subject->id)->call('setRole', 'superuser');

        $this->assertSame(Role::User, $subject->fresh()->role);
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        /*
         * The guard that matters most. Registration is disabled by default, so an admin who
         * demoted themselves as the only admin would leave no route back in except
         * minizo:make-admin on the host.
         */
        $admin = User::factory()->admin()->create();

        $this->modal($admin)
            ->call('manage', $admin->id)
            ->call('setRole', 'user')
            ->assertForbidden();

        $this->assertSame(Role::Admin, $admin->fresh()->role);
    }

    public function test_the_modal_explains_why_your_own_account_is_read_only(): void
    {
        // Rather than leaving an admin to wonder why every control on their own row is inert.
        $admin = User::factory()->admin()->create();

        $this->modal($admin)
            ->call('manage', $admin->id)
            ->assertSet('editable', false)
            ->assertSee('This is your own account', escape: false);
    }

    // ------------------------------------------------------------- folder access

    public function test_toggling_all_folders_on_stores_the_sentinel(): void
    {
        // Not an expanded list: the sentinel is what makes a folder created later
        // automatically visible, which is the whole point of having one.
        $subject = User::factory()->withoutFolders()->create();

        $this->modal()->call('manage', $subject->id)->call('toggleAllFolders');

        $this->assertSame(['*'], $subject->fresh()->folder_access);
    }

    public function test_toggling_all_folders_off_clears_the_list_entirely(): void
    {
        $subject = User::factory()->create();

        $this->modal()->call('manage', $subject->id)->call('toggleAllFolders');

        $this->assertSame([], $subject->fresh()->folder_access);
    }

    public function test_turning_one_folder_off_expands_the_sentinel(): void
    {
        /*
         * THE folder-chip behaviour. An all-access user with one folder toggled off becomes
         * an explicit list of everything except that folder - otherwise "all but one" could
         * only be expressed by clearing everything and re-picking the rest by hand.
         */
        $subject = User::factory()->create();

        $this->assertSame(['*'], $subject->folder_access);

        $this->modal()->call('manage', $subject->id)->call('toggleFolder', 'Folk');

        $access = $subject->fresh()->folder_access;

        sort($access);

        $this->assertSame(['Classic', 'Spanish'], $access);
        $this->assertFalse($subject->fresh()->folderAccess()->allows('Folk'));
    }

    public function test_turning_a_folder_on_adds_it_to_an_explicit_list(): void
    {
        $subject = User::factory()->withFolders(['Spanish'])->create();

        $this->modal()->call('manage', $subject->id)->call('toggleFolder', 'Folk');

        $access = $subject->fresh()->folder_access;

        sort($access);

        $this->assertSame(['Folk', 'Spanish'], $access);
    }

    public function test_granting_every_folder_one_by_one_does_not_become_the_sentinel(): void
    {
        // Different from ["*"]: an explicit list of every current folder means "these
        // three", where the sentinel means "whatever exists". A folder created tomorrow
        // must not appear for someone whose access was granted folder by folder.
        $subject = User::factory()->withoutFolders()->create();

        $component = $this->modal()->call('manage', $subject->id);

        foreach (['Spanish', 'Folk', 'Classic'] as $folder) {
            $component->call('toggleFolder', $folder);
        }

        $this->assertNotSame(['*'], $subject->fresh()->folder_access);
        $this->assertFalse($subject->fresh()->folderAccess()->allowsAll());

        Storage::disk('music')->makeDirectory('Later');

        $this->assertFalse($subject->fresh()->folderAccess()->allows('Later'));
    }

    public function test_an_admin_cannot_change_their_own_folder_access(): void
    {
        $admin = User::factory()->admin()->create();

        $this->modal($admin)
            ->call('manage', $admin->id)
            ->call('toggleFolder', 'Spanish')
            ->assertForbidden();
    }

    // --------------------------------------------------------------- permissions

    public function test_each_permission_can_be_granted_and_revoked(): void
    {
        $subject = User::factory()->viewOnly()->create();

        $component = $this->modal()->call('manage', $subject->id);

        foreach (Permission::cases() as $permission) {
            $component->call('togglePermission', $permission->value);

            $this->assertTrue(
                $subject->fresh()->permissions()->granted($permission),
                "{$permission->value} should have been granted",
            );

            $component->call('togglePermission', $permission->value);

            $this->assertFalse(
                $subject->fresh()->permissions()->granted($permission),
                "{$permission->value} should have been revoked",
            );
        }
    }

    public function test_an_unknown_permission_is_a_404(): void
    {
        $subject = User::factory()->create();

        $this->modal()
            ->call('manage', $subject->id)
            ->call('togglePermission', 'become-root')
            ->assertNotFound();
    }

    public function test_revoking_the_downloader_permission_clears_the_locks_with_it(): void
    {
        /*
         * A folder lock on an account that cannot download is dead configuration - and worse,
         * it would silently come back into force if the permission were ever re-granted.
         */
        $subject = User::factory()->lockedDownloader('Spanish', AudioFormat::Flac)->create();

        $this->modal()
            ->call('manage', $subject->id)
            ->call('togglePermission', Permission::Downloader->value);

        $subject->refresh();

        $this->assertFalse($subject->permissions()->granted(Permission::Downloader));
        $this->assertNull($subject->download_folder_lock);
        $this->assertNull($subject->download_format_lock);
    }

    public function test_an_admin_cannot_change_their_own_permissions(): void
    {
        $admin = User::factory()->admin()->create();

        $this->modal($admin)
            ->call('manage', $admin->id)
            ->call('togglePermission', Permission::Delete->value)
            ->assertForbidden();
    }

    public function test_the_share_toggle_reflects_the_grant_not_the_instance_switch(): void
    {
        /*
         * A Share toggle rendered off because the instance switch was off would look like the
         * grant had been revoked, which is a different fact. This modal edits grants.
         */
        Sharing::fake(false);

        try {
            $subject = User::factory()->withPermissions([Permission::Share])->create();

            $component = $this->modal()->call('manage', $subject->id);

            $this->assertTrue($component->instance()->permissions->granted(Permission::Share));
        } finally {
            Sharing::clearFake();
        }
    }

    // ------------------------------------------------------------ account active

    public function test_an_admin_can_disable_and_re_enable_an_account(): void
    {
        $subject = User::factory()->create();

        $component = $this->modal()->call('manage', $subject->id);

        $component->call('toggleActive');
        $this->assertFalse($subject->fresh()->is_active);

        $component->call('toggleActive');
        $this->assertTrue($subject->fresh()->is_active);
    }

    public function test_disabling_an_account_purges_its_sessions(): void
    {
        /*
         * EnsureUserIsActive catches the NEXT request, so without this the person keeps
         * browsing until they happen to reload. Purging the rows closes that window.
         */
        config()->set('session.driver', 'database');

        $subject = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'session-to-kill',
            'user_id' => $subject->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        $this->modal()->call('manage', $subject->id)->call('toggleActive');

        $this->assertDatabaseMissing('sessions', ['id' => 'session-to-kill']);
    }

    public function test_disabling_an_account_leaves_its_share_links_alone_by_default(): void
    {
        // The considered default: disabling revokes login, not what someone already handed
        // out. See minizo.sharing.revoke_on_user_disable for the instances that want both.
        $subject = User::factory()->create();
        $share = Share::factory()->for($subject)->create(['revoked_at' => null]);

        $this->modal()->call('manage', $subject->id)->call('toggleActive');

        $this->assertNull($share->refresh()->revoked_at);
    }

    public function test_disabling_an_account_revokes_its_share_links_when_configured(): void
    {
        // The opposite view, for an instance where a disabled account is usually a
        // COMPROMISED one - in which case every link it published is suspect.
        config()->set('minizo.sharing.revoke_on_user_disable', true);

        $subject = User::factory()->create();
        $live = Share::factory()->for($subject)->create(['revoked_at' => null]);
        $someoneElse = Share::factory()->create(['revoked_at' => null]);

        $this->modal()->call('manage', $subject->id)->call('toggleActive');

        // Revoked, not deleted, so the 30-day audit trail survives the decision.
        $this->assertNotNull($live->refresh()->revoked_at);
        $this->assertModelExists($live);

        $this->assertNull($someoneElse->refresh()->revoked_at, 'only the disabled account is touched');
    }

    public function test_an_already_dead_link_is_not_re_revoked(): void
    {
        config()->set('minizo.sharing.revoke_on_user_disable', true);

        $subject = User::factory()->create();

        $revokedAt = now()->subDays(3)->startOfSecond();
        $dead = Share::factory()->for($subject)->create(['revoked_at' => $revokedAt]);

        $this->modal()->call('manage', $subject->id)->call('toggleActive');

        /*
         * Rewriting the timestamp would move a three-day-old revocation to today and reset
         * its 30-day retention clock with it. Compared to the second, because the column
         * stores no microseconds and a raw Carbon comparison would fail on them alone.
         */
        $this->assertSame(
            $revokedAt->toDateTimeString(),
            $dead->refresh()->revoked_at->toDateTimeString(),
        );
    }

    public function test_re_enabling_an_account_does_not_bring_its_links_back(): void
    {
        config()->set('minizo.sharing.revoke_on_user_disable', true);

        $subject = User::factory()->create(['is_active' => false]);
        $share = Share::factory()->for($subject)->create(['revoked_at' => now()]);

        $this->modal()->call('manage', $subject->id)->call('toggleActive');

        // One-way. Un-revoking would resurrect a URL that has been dead for an unknown
        // length of time and may have been passed on since. An admin who wants the link
        // back can issue a fresh one from the Share links screen.
        $this->assertTrue($subject->refresh()->is_active);
        $this->assertNotNull($share->refresh()->revoked_at);
    }

    public function test_the_toast_says_how_many_links_were_revoked(): void
    {
        config()->set('minizo.sharing.revoke_on_user_disable', true);

        $subject = User::factory()->create(['name' => 'Bea']);
        Share::factory()->for($subject)->count(2)->create(['revoked_at' => null]);

        // Silently killing someone's published links is a surprising side effect of a button
        // labelled "disable", so it is said out loud.
        $this->modal()
            ->call('manage', $subject->id)
            ->call('toggleActive')
            ->assertDispatched('toast-show', fn (string $event, array $data): bool => str_contains(
                json_encode($data),
                '2 share links were revoked',
            ));
    }

    public function test_re_enabling_an_account_leaves_other_sessions_alone(): void
    {
        config()->set('session.driver', 'database');

        $other = User::factory()->create();
        $subject = User::factory()->disabled()->create();

        DB::table('sessions')->insert([
            'id' => 'someone-elses',
            'user_id' => $other->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        $this->modal()->call('manage', $subject->id)->call('toggleActive');

        $this->assertDatabaseHas('sessions', ['id' => 'someone-elses']);
    }

    public function test_an_admin_cannot_disable_themselves(): void
    {
        // Same reasoning as the role guard: the last admin must not be able to lock the
        // instance.
        $admin = User::factory()->admin()->create();

        $this->modal($admin)
            ->call('manage', $admin->id)
            ->call('toggleActive')
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    // ------------------------------------------------------- downloader locks

    public function test_a_folder_lock_can_be_set_and_cleared(): void
    {
        $subject = User::factory()->create();

        $component = $this->modal()->call('manage', $subject->id);

        $component->call('setFolderLock', 'Spanish');
        $this->assertSame('Spanish', $subject->fresh()->download_folder_lock);

        // '' is the "Any allowed folder" option.
        $component->call('setFolderLock', '');
        $this->assertNull($subject->fresh()->download_folder_lock);
    }

    public function test_a_lock_cannot_point_at_a_folder_that_does_not_exist(): void
    {
        // Otherwise every download from that account would fail with an administrative error
        // nobody would connect to a select they set weeks earlier.
        $subject = User::factory()->create();

        $this->modal()->call('manage', $subject->id)->call('setFolderLock', 'NoSuchFolder');

        $this->assertNull($subject->fresh()->download_folder_lock);
    }

    public function test_a_format_lock_can_be_set_and_cleared(): void
    {
        $subject = User::factory()->create();

        $component = $this->modal()->call('manage', $subject->id);

        $component->call('setFormatLock', 'flac');
        $this->assertSame(AudioFormat::Flac, $subject->fresh()->download_format_lock);

        $component->call('setFormatLock', '');
        $this->assertNull($subject->fresh()->download_format_lock);
    }

    public function test_an_admin_cannot_lock_their_own_downloads(): void
    {
        $admin = User::factory()->admin()->create();

        $this->modal($admin)
            ->call('manage', $admin->id)
            ->call('setFolderLock', 'Spanish')
            ->assertForbidden();
    }

    // -------------------------------------------------------------- adding a user

    public function test_an_admin_can_create_an_account(): void
    {
        $this->modal()
            ->call('startAdding')
            ->set('name', 'Newcomer')
            ->set('email', 'new@example.test')
            ->call('store')
            ->assertHasNoErrors()
            ->assertDispatched('users-updated');

        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'name' => 'Newcomer']);
    }

    public function test_a_new_account_starts_with_nothing(): void
    {
        // Least privilege: an admin grants folders and permissions in the modal that
        // opens next. A new account that could already read the whole library would make
        // that screen decorative.
        $this->modal()
            ->call('startAdding')
            ->set('name', 'Newcomer')
            ->set('email', 'new@example.test')
            ->call('store');

        $created = User::whereEmail('new@example.test')->sole();

        $this->assertSame(Role::User, $created->role);
        $this->assertSame([], $created->folder_access);
        $this->assertTrue($created->is_active);

        foreach (Permission::cases() as $permission) {
            $this->assertFalse($created->permissions()->granted($permission));
        }
    }

    public function test_creating_an_account_opens_it_for_management(): void
    {
        // A locked-down account is not useful yet, so the two steps are chained.
        $component = $this->modal()
            ->call('startAdding')
            ->set('name', 'Newcomer')
            ->set('email', 'new@example.test')
            ->call('store');

        $created = User::whereEmail('new@example.test')->sole();

        $component->assertSet('userId', $created->id)->assertSet('adding', false);
    }

    public function test_a_duplicate_email_is_reported_on_the_field(): void
    {
        $existing = User::factory()->create();

        $this->modal()
            ->call('startAdding')
            ->set('name', 'Copy')
            ->set('email', $existing->email)
            ->call('store')
            ->assertHasErrors('email');
    }

    public function test_a_missing_name_or_email_is_reported(): void
    {
        $this->modal()
            ->call('startAdding')
            ->set('name', '')
            ->set('email', 'not-an-email')
            ->call('store')
            ->assertHasErrors(['name', 'email']);
    }

    public function test_a_non_admin_cannot_create_an_account(): void
    {
        $this->modal(User::factory()->create())
            ->call('startAdding')
            ->assertForbidden();

        $this->modal(User::factory()->create())
            ->set('name', 'Sneaky')
            ->set('email', 'sneaky@example.test')
            ->call('store')
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.test']);
    }

    public function test_the_new_account_gets_an_unusable_password_not_a_known_one(): void
    {
        // No invitation email is sent: MAIL_MAILER defaults to `log`, so an invitation flow
        // would fail on most installs. What must not happen is a predictable default password.
        $this->modal()
            ->call('startAdding')
            ->set('name', 'Newcomer')
            ->set('email', 'new@example.test')
            ->call('store');

        $created = User::whereEmail('new@example.test')->sole();

        foreach (['password', 'minizo', 'Newcomer', 'new@example.test', ''] as $guess) {
            $this->assertFalse(
                Hash::check($guess, $created->password),
                "[{$guess}] must not be the new account's password",
            );
        }
    }

    // ---------------------------------------------------------------------- email

    /*
     * Email editing lives here because Settings locks the field and tells the user to
     * "contact an administrator". Without this, that sentence would point at nobody: an
     * address set at account creation could never be corrected.
     */

    public function test_an_admin_can_change_an_accounts_email(): void
    {
        $subject = User::factory()->create(['email' => 'typo@exmaple.com']);

        $this->modal()
            ->call('manage', $subject->id)
            ->assertSet('editingEmail', 'typo@exmaple.com')
            ->set('editingEmail', 'correct@example.com')
            ->call('updateEmail')
            ->assertHasNoErrors();

        $this->assertSame('correct@example.com', $subject->refresh()->email);
    }

    public function test_a_changed_email_stays_verified(): void
    {
        $subject = User::factory()->create(['email_verified_at' => now()]);

        $this->modal()
            ->call('manage', $subject->id)
            ->set('editingEmail', 'new@example.com')
            ->call('updateEmail');

        /*
         * Minizo sends no mail - accounts are created force-verified for that reason - so
         * nulling this would strand the account behind a link that can never arrive.
         */
        $this->assertNotNull($subject->refresh()->email_verified_at);
    }

    public function test_an_email_already_in_use_is_reported_on_the_field(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $subject = User::factory()->create(['email' => 'mine@example.com']);

        $this->modal()
            ->call('manage', $subject->id)
            ->set('editingEmail', 'taken@example.com')
            ->call('updateEmail')
            ->assertHasErrors('editingEmail');

        $this->assertSame('mine@example.com', $subject->refresh()->email);
    }

    public function test_keeping_the_same_email_is_not_a_uniqueness_error(): void
    {
        // The uniqueness rule has to ignore the subject, or saving any other change on the
        // modal would report the user's own address as taken.
        $subject = User::factory()->create(['email' => 'mine@example.com']);

        $this->modal()
            ->call('manage', $subject->id)
            ->call('updateEmail')
            ->assertHasNoErrors();

        $this->assertSame('mine@example.com', $subject->refresh()->email);
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        $subject = User::factory()->create(['email' => 'mine@example.com']);

        $this->modal()
            ->call('manage', $subject->id)
            ->set('editingEmail', 'not-an-email')
            ->call('updateEmail')
            ->assertHasErrors('editingEmail');

        $this->assertSame('mine@example.com', $subject->refresh()->email);
    }

    public function test_an_admin_can_correct_their_own_email(): void
    {
        // Unlike role, folder access and permissions, this is not a privilege an admin could
        // use to entrench themselves - so it is governed by `update`, not `setPermissions`.
        $admin = User::factory()->admin()->create(['email' => 'admin@exmaple.com']);

        $this->modal($admin)
            ->call('manage', $admin->id)
            ->set('editingEmail', 'admin@example.com')
            ->call('updateEmail')
            ->assertHasNoErrors();

        $this->assertSame('admin@example.com', $admin->refresh()->email);
    }

    public function test_a_non_admin_cannot_change_anyones_email(): void
    {
        $subject = User::factory()->create(['email' => 'mine@example.com']);

        $this->modal(User::factory()->create())
            ->set('editingEmail', 'hijacked@example.com')
            ->call('updateEmail')
            ->assertStatus(404);

        $this->assertSame('mine@example.com', $subject->refresh()->email);
    }
}
