<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Settings, as one card grid. */
class SettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedSession(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    // ------------------------------------------------------------------- routing

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->get(route('settings.edit'))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_settings_screen_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Profile')
            ->assertSee('Password')
            ->assertSee('Delete account');
    }

    #[Test]
    public function the_appearance_switch_is_in_the_header_not_on_this_screen(): void
    {
        // It applies instantly and has no server state, so it belongs where you can
        // reach it from any screen rather than behind a navigation.
        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.edit'))
            ->assertOk();

        // Rendered once, by the layout, with each option named for a screen reader.
        $response->assertSee('$flux.appearance', escape: false);

        foreach (['Light', 'Dark', 'System'] as $option) {
            $response->assertSee('aria-label="'.$option.'"', escape: false);
        }
    }

    #[Test]
    public function the_old_tab_routes_redirect_to_it(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['profile.edit', 'security.edit', 'appearance.edit'] as $name) {
            $this->get(route($name))->assertRedirect('/settings');
        }
    }

    #[Test]
    public function the_passkey_endpoints_document_still_resolves(): void
    {
        /*
         * security.edit is published as this site's passkey enrolment URL, so a browser or
         * password manager that cached the document keeps following it. Kept as a redirect
         * rather than deleted for exactly this reason.
         */
        $this->get(route('well-known.passkeys'))
            ->assertOk()
            ->assertJson([
                'enroll' => route('security.edit'),
                'manage' => route('security.edit'),
            ]);
    }

    // ------------------------------------------------------------------- profile

    #[Test]
    public function the_name_can_be_updated(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.index')
            ->set('name', 'Test User')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('Test User', $user->refresh()->name);
    }

    #[Test]
    public function the_pagination_size_can_be_updated(): void
    {
        $user = User::factory()->create(['pagination_size' => 50]);

        Livewire::actingAs($user)
            ->test('pages::settings.index')
            ->set('paginationSize', 100)
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame(100, $user->refresh()->pagination_size);
    }

    #[Test]
    public function the_pagination_size_is_bounded(): void
    {
        $user = User::factory()->create(['pagination_size' => 50]);

        // The column feeds a query LIMIT, so an out-of-range value is a rejected input rather
        // than something clamped silently on the way in.
        Livewire::actingAs($user)
            ->test('pages::settings.index')
            ->set('paginationSize', 100_000)
            ->call('updateProfileInformation')
            ->assertHasErrors('paginationSize');

        $this->assertSame(50, $user->refresh()->pagination_size);
    }

    #[Test]
    public function the_email_is_read_only(): void
    {
        $user = User::factory()->create(['email' => 'original@example.com']);

        $this->actingAs($user)
            ->get(route('settings.edit'))
            ->assertSee('Email is locked')
            ->assertSee('contact an administrator', escape: false);

        /*
         * Not just a disabled input. Email is absent from the component's properties AND its
         * rule set, so a hand-built Livewire payload has nothing to set - which is the part a
         * readonly attribute would not have covered.
         */
        Livewire::actingAs($user)
            ->test('pages::settings.index')
            ->set('name', 'Test User')
            ->call('updateProfileInformation');

        $this->assertSame('original@example.com', $user->refresh()->email);
    }

    // ------------------------------------------------------------------ password

    #[Test]
    public function the_password_can_be_updated(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        Livewire::actingAs($user)
            ->test('pages::settings.index')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    #[Test]
    public function the_current_password_must_be_correct(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $component = Livewire::actingAs($user)
            ->test('pages::settings.index')
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasErrors(['current_password']);

        // Cleared on failure, so a rejected attempt does not leave the proposed new password
        // sitting in the DOM for whoever is at the keyboard next.
        $component->assertSet('password', '')->assertSet('current_password', '');
    }

    #[Test]
    public function changing_the_password_needs_no_password_confirmation(): void
    {
        // The password form asks for the current password itself, so gating it behind
        // password.confirm as well would be asking for the same secret twice.
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this->actingAs($user)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Current password');
    }

    // ----------------------------------------------------------------------- 2FA

    #[Test]
    public function the_two_factor_card_renders_when_the_feature_is_on(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

        $this->actingAs(User::factory()->create())
            ->confirmedSession()
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Two-factor authentication')
            ->assertSee('Enable 2FA');
    }

    #[Test]
    public function the_two_factor_card_is_absent_when_the_feature_is_off(): void
    {
        config(['fortify.features' => []]);

        $this->actingAs(User::factory()->create())
            ->confirmedSession()
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Password')
            ->assertDontSee('Two-factor authentication')
            ->assertDontSee('Sign in with a fingerprint');
    }

    #[Test]
    public function the_two_factor_card_asks_for_a_password_first(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

        // Locked rather than hidden: the card still explains what it is, which is why the
        // gate could move off the route without the screen losing anything.
        $this->actingAs(User::factory()->create())
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Two-factor authentication')
            ->assertSee('Confirm your password to manage two-factor authentication.', escape: false)
            ->assertDontSee('Enable 2FA');
    }

    #[Test]
    public function two_factor_cannot_be_disabled_without_confirming_a_password(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // Hiding the button is not the gate; the action refuses.
        Livewire::actingAs($user)
            ->test('pages::settings.index')
            ->call('disableTwoFactor')
            ->assertForbidden();

        $this->assertNotNull($user->refresh()->two_factor_secret);
    }

    #[Test]
    public function two_factor_can_be_disabled_once_the_password_is_confirmed(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // session(), not withSession(): the latter stages state for an HTTP request, and
        // Livewire::test() does not make one.
        session(['auth.password_confirmed_at' => time()]);

        Livewire::actingAs($user)
            ->test('pages::settings.index')
            ->assertSet('twoFactorEnabled', true)
            ->call('disableTwoFactor')
            ->assertSet('twoFactorEnabled', false);

        $this->assertNull($user->refresh()->two_factor_secret);
    }

    #[Test]
    public function an_abandoned_two_factor_setup_is_cleared_on_load(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

        $user = User::factory()->create();

        $user->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => null,
        ])->save();

        // Someone opened the modal, got a QR code and closed the tab. Left alone it would
        // read as half-enabled forever.
        Livewire::actingAs($user)
            ->test('pages::settings.index')
            ->assertSet('twoFactorEnabled', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ]);
    }

    // ------------------------------------------------------------------ passkeys

    #[Test]
    public function the_passkey_card_renders_once_the_password_is_confirmed(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::passkeys(['confirmPassword' => true]);

        $this->actingAs(User::factory()->create())
            ->confirmedSession()
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Passkeys')
            ->assertSee('No passkeys yet', escape: false);
    }

    #[Test]
    public function the_passkey_card_asks_for_a_password_first(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::passkeys(['confirmPassword' => true]);

        $this->actingAs(User::factory()->create())
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Confirm your password to manage passkeys.', escape: false)
            ->assertDontSee('No passkeys yet');
    }

    #[Test]
    public function a_passkey_cannot_be_removed_without_confirming_a_password(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::passkeys(['confirmPassword' => true]);

        Livewire::actingAs(User::factory()->create())
            ->test('pages::settings.index')
            ->call('deletePasskey')
            ->assertForbidden();
    }

    #[Test]
    public function confirming_a_password_sends_the_user_back_to_settings(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.index')
            ->call('confirmPassword')
            ->assertRedirect(route('password.confirm'));

        // Without the intended URL, Fortify drops the user on the Download screen after
        // confirming - wondering where Settings went.
        $this->assertSame(route('settings.edit'), session('url.intended'));
    }

    // ------------------------------------------------------------ delete account

    #[Test]
    public function the_account_can_be_deleted(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    #[Test]
    public function the_last_admin_cannot_delete_themselves(): void
    {
        /*
         * Every administrative gate resolves isAdmin(), so an instance with no admin left
         * cannot create users, manage folders or touch the sharing switch - and
         * NewUserDefaults only auto-promotes into an EMPTY table, so it does not heal
         * itself while other accounts exist. Getting out needs shell access.
         */
        $admin = User::factory()->admin()->create();
        User::factory()->create();

        Livewire::actingAs($admin)
            ->test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors('password');

        $this->assertModelExists($admin);
    }

    #[Test]
    public function an_admin_can_delete_themselves_once_another_admin_exists(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors();

        $this->assertNull($admin->fresh());
    }

    #[Test]
    public function a_deactivated_admin_does_not_count_as_the_one_left_behind(): void
    {
        // They cannot sign in, so leaving them would lock the instance just the same.
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->disabled()->create();

        Livewire::actingAs($admin)
            ->test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors('password');

        $this->assertModelExists($admin);
    }

    #[Test]
    public function deleting_the_account_needs_the_right_password(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.delete-user-modal')
            ->set('password', 'wrong-password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }
}
