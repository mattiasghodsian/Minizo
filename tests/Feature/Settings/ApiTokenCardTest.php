<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The API access card: generating and revoking the feed token. */
class ApiTokenCardTest extends TestCase
{
    use RefreshDatabase;

    // ---- rendering

    #[Test]
    public function it_documents_the_endpoint_before_any_token_exists(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::settings.api-token')
            ->assertSee('API access')
            ->assertSee('GET')
            ->assertSee(route('api.feed'))
            ->assertSee('Generate token')
            ->assertSee('curl -H')
            ->assertDontSee('Regenerate token');
    }

    #[Test]
    public function the_card_appears_on_the_settings_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('API access');
    }

    #[Test]
    public function it_shows_the_active_state_for_a_user_who_already_has_one(): void
    {
        Livewire::actingAs(User::factory()->withApiToken()->create())
            ->test('pages::settings.api-token')
            ->assertSee('Active')
            ->assertSee('Never used')
            ->assertSee('Regenerate token')
            ->assertSee('Revoke');
    }

    // ---- generating

    #[Test]
    public function it_generates_a_token_and_shows_it_once(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('pages::settings.api-token')
            ->call('generate');

        $plain = $component->get('plainToken');

        $this->assertStringStartsWith(ApiToken::PREFIX, $plain);

        // Shown in the field and interpolated into the runnable curl line.
        $component->assertSee($plain)
            ->assertSee('Bearer '.$plain);

        // Stored as its hash, never as the plaintext.
        $user->refresh();
        $this->assertSame(ApiToken::hash($plain), $user->api_token);
        $this->assertNotSame($plain, $user->api_token);

        // A fresh mount cannot recover it.
        Livewire::actingAs($user)
            ->test('pages::settings.api-token')
            ->assertSet('plainToken', null)
            ->assertDontSee($plain);
    }

    #[Test]
    public function generating_replaces_an_existing_token(): void
    {
        $user = User::factory()->withApiToken('mnz_first')->create();

        Livewire::actingAs($user)->test('pages::settings.api-token')->call('generate');

        $this->assertNull(ApiToken::resolve('mnz_first'));
    }

    // ---- revoking

    #[Test]
    public function it_revokes_a_token(): void
    {
        $user = User::factory()->withApiToken('mnz_live')->create();

        Livewire::actingAs($user)
            ->test('pages::settings.api-token')
            ->call('revoke')
            ->assertSet('plainToken', null)
            ->assertSee('Generate token');

        $user->refresh();

        $this->assertNull($user->api_token);
        $this->assertNull($user->api_token_created_at);
        $this->assertNull(ApiToken::resolve('mnz_live'));
    }

    // ---- scope

    #[Test]
    public function it_only_ever_touches_the_authenticated_users_own_token(): void
    {
        $other = User::factory()->withApiToken('mnz_theirs')->create();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::settings.api-token')
            ->call('generate');

        $this->assertSame(ApiToken::hash('mnz_theirs'), $other->fresh()->api_token);

        Livewire::actingAs(User::factory()->withApiToken('mnz_mine')->create())
            ->test('pages::settings.api-token')
            ->call('revoke');

        $this->assertSame(ApiToken::hash('mnz_theirs'), $other->fresh()->api_token);
    }
}
