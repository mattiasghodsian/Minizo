<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** "Disabled users cannot log in." */
class DisabledUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_disabled_user_cannot_log_in(): void
    {
        $user = User::factory()->disabled()->create(['email' => 'kev@minizo.test']);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors();
    }

    public function test_an_active_user_can_still_log_in(): void
    {
        // Guards against the check being inverted or applied too broadly.
        $user = User::factory()->create(['email' => 'maria@minizo.test']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_still_fails_for_an_active_user(): void
    {
        // authenticateUsing replaces Fortify's own credential check, so the
        // password comparison is now ours and needs its own test.
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        $this->assertGuest();
    }

    public function test_an_existing_session_is_terminated_once_the_account_is_disabled(): void
    {
        // Deactivating someone does not touch sessions they already hold, so
        // without the middleware they would keep browsing until they logged out.
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('download'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('download'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_middleware_leaves_guests_alone(): void
    {
        // It runs on the whole web group, so it must be a no-op when nobody is
        // signed in - otherwise the login page itself would break.
        $this->get(route('login'))->assertOk();
    }

    public function test_an_active_user_is_not_logged_out_by_the_middleware(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('download'))->assertOk();

        $this->assertTrue(Auth::check());
    }
}
