<?php

namespace Tests\Feature;

use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Minizo serves HTTP from `artisan serve`, so there is no web server in the image to
 * carry these. Without the middleware the app ships none of them.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_response_carries_the_headers(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('download'));

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('Strict-Transport-Security');

        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    #[Test]
    public function the_public_share_page_carries_them_too(): void
    {
        /*
         * The one that matters most here is Referrer-Policy. The share URL IS the secret,
         * so without it any outbound link from this page hands the token to the
         * destination in the Referer header.
         */
        $share = Share::factory()->create();

        $this->get(route('share.show', $share->token))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function hsts_is_sent_only_over_https(): void
    {
        // Sent on a plain-HTTP request it would pin a browser to a scheme a LAN install
        // may not serve at all.
        config(['app.url' => 'https://minizo.test']);

        $this->actingAs(User::factory()->create())
            ->get('https://minizo.test/download')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
