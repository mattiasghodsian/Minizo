<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MinizoMailTestCommandTest extends TestCase
{
    public function test_it_sends_a_message_with_useful_contents(): void
    {
        // The `array` transport rather than Mail::fake(): the command sends a raw
        // message, and Mail::fake()'s assertions only see Mailables. This captures
        // the real Symfony message, which lets us check what an operator would
        // actually receive - more useful than a count.
        $this->artisan('minizo:mail:test', ['email' => 'ops@minizo.test', '--mailer' => 'array'])
            ->assertSuccessful();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);

        $sent = $messages[0]->getOriginalMessage();

        $this->assertSame('ops@minizo.test', $sent->getTo()[0]->getAddress());
        $this->assertStringContainsString('SMTP test', $sent->getSubject());

        // The body has to identify which instance sent it - otherwise a test mail
        // arriving in a shared inbox tells you nothing.
        $body = $sent->getTextBody();
        $this->assertStringContainsString(config('app.url'), $body);
        $this->assertStringContainsString('Mailer:', $body);
    }

    public function test_it_rejects_a_malformed_address_without_sending(): void
    {
        Mail::fake();

        $this->artisan('minizo:mail:test', ['email' => 'not-an-email'])
            ->expectsOutputToContain('is not a valid email address')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_it_rejects_an_unknown_mailer(): void
    {
        Mail::fake();

        $this->artisan('minizo:mail:test', ['email' => 'ops@minizo.test', '--mailer' => 'carrier-pigeon'])
            ->expectsOutputToContain('No mailer named [carrier-pigeon]')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_it_warns_when_the_mailer_discards_mail(): void
    {
        // The whole point of the warning: `log` accepts everything and delivers
        // nothing, which is why people believe their SMTP works untested.
        config()->set('mail.default', 'log');

        $this->artisan('minizo:mail:test', ['email' => 'ops@minizo.test'])
            ->expectsOutputToContain('does not send anything')
            ->assertSuccessful();
    }

    public function test_it_does_not_warn_for_a_real_transport(): void
    {
        Mail::fake();

        config()->set('mail.default', 'smtp');

        $this->artisan('minizo:mail:test', ['email' => 'ops@minizo.test'])
            ->doesntExpectOutputToContain('does not send anything')
            ->assertSuccessful();
    }

    public function test_it_never_prints_the_smtp_password(): void
    {
        Mail::fake();

        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.password', 'super-secret-value');

        $this->artisan('minizo:mail:test', ['email' => 'ops@minizo.test'])
            ->doesntExpectOutputToContain('super-secret-value')
            ->expectsOutputToContain('(set)')
            ->assertSuccessful();
    }

    public function test_it_flags_the_literal_string_null_in_env(): void
    {
        Mail::fake();

        // MAIL_USERNAME=null in .env arrives as the 4-character string "null", not
        // PHP null - and then gets offered to the relay as a username. The starter
        // .env ships exactly this, so it is worth calling out rather than passing on.
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.username', 'null');

        $this->artisan('minizo:mail:test', ['email' => 'ops@minizo.test'])
            ->expectsOutputToContain('literal string')
            ->assertSuccessful();
    }
}
