<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class MinizoMailTest extends Command
{
    protected $signature = 'minizo:mail:test
                            {email? : Where to send the test message}
                            {--mailer= : Override the configured mailer, e.g. --mailer=smtp}';

    protected $description = 'Send a test email and report what the transport did';

    /** Send one test message and report how the mailer is configured. */
    public function handle(): int
    {
        $recipient = (string) ($this->argument('email') ?: $this->ask('Send the test message to'));

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("[{$recipient}] is not a valid email address.");

            return self::FAILURE;
        }

        $mailer = (string) ($this->option('mailer') ?: config('mail.default'));

        if (! is_array(config("mail.mailers.{$mailer}"))) {
            $this->components->error("No mailer named [{$mailer}] is configured.");
            $this->line('  Available: <fg=gray>'.implode(', ', array_keys((array) config('mail.mailers'))).'</>');

            return self::FAILURE;
        }

        $this->newLine();
        $this->reportTransport($mailer);

        // A mailer that discards mail must not be allowed to report success. Warn
        // and point at --mailer, which is how you test SMTP while leaving the app
        // itself on the log driver.
        $discards = in_array(config("mail.mailers.{$mailer}.transport"), ['log', 'array'], true);

        if ($discards) {
            $this->newLine();
            $this->components->warn("The [{$mailer}] mailer does not send anything.");
            $this->line('  Nothing will reach the inbox. To exercise a real relay:');
            $this->line('  <fg=gray>php artisan minizo:mail:test '.$recipient.' --mailer=smtp</>');
        }

        $this->newLine();

        return $this->send($mailer, $recipient, $discards);
    }

    /** Send the test message and report what happened to it. */
    private function send(string $mailer, string $recipient, bool $discards): int
    {
        $sentAt = now()->toDateTimeString();
        $subject = sprintf('[%s] SMTP test — %s', config('app.name', 'Minizo'), $sentAt);

        $startedAt = microtime(true);

        try {
            Mail::mailer($mailer)->raw($this->body($mailer, $sentAt), function (Message $message) use ($recipient, $subject): void {
                $message->to($recipient)->subject($subject);
            });
        } catch (TransportExceptionInterface $e) {
            // The transport's own message is the diagnosis - print it unedited.
            $this->components->error('The transport rejected the message.');
            $this->line('  <fg=red>'.$e->getMessage().'</>');
            $this->newLine();
            $this->hint($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            // Configuration faults (a missing API key, an unresolvable driver)
            // surface here rather than as a transport error.
            $this->components->error(class_basename($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $elapsed = round((microtime(true) - $startedAt) * 1000);

        $this->components->info(sprintf('Handed to the [%s] mailer in %dms.', $mailer, $elapsed));

        if ($discards) {
            $this->line('  <fg=yellow>Not delivered</> — check storage/logs/laravel.log for the rendered message.');
        } else {
            // "Accepted" is the honest word. A relay taking the message does not
            // guarantee an inbox: SPF, DKIM and spam filtering all come later.
            $this->line("  Accepted for delivery to <fg=green>{$recipient}</>.");
            $this->line('  <fg=gray>If it does not arrive, the relay accepted it and something downstream dropped it — check spam, then SPF/DKIM for '.config('mail.from.address').'.</>');
        }

        return self::SUCCESS;
    }

    /** Print the transport, host and from address the mailer will use. */
    private function reportTransport(string $mailer): void
    {
        $config = (array) config("mail.mailers.{$mailer}");
        $transport = $config['transport'] ?? 'unknown';

        $this->line("  Mailer <fg=gray>[{$mailer}]</> transport <fg=gray>[{$transport}]</>");

        $rows = [
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            // MAIL_SCHEME is how Laravel 11+ picks TLS; null means "decide by port".
            'scheme' => $config['scheme'] ?? null,
            'username' => $config['username'] ?? null,
            // Never print the password. Whether one is SET is the useful fact.
            'password' => filled($config['password'] ?? null) ? '******** (set)' : null,
            'timeout' => $config['timeout'] ?? null,
            'from' => config('mail.from.address'),
            'from name' => config('mail.from.name'),
        ];

        foreach ($rows as $label => $value) {
            // "null" as a literal string is a real and confusing footgun: .env
            // values like MAIL_USERNAME=null arrive as the 4-character string
            // "null", not as PHP null, and then get sent as a username.
            if ($value === null || $value === '') {
                continue;
            }

            $suffix = $value === 'null'
                ? ' <fg=yellow>← literal string "null" in .env; leave the value empty instead</>'
                : '';

            $this->line(sprintf('    %-10s %s%s', $label, $value, $suffix));
        }
    }

    /** Turn the transport's message into something actionable. */
    private function hint(string $error): void
    {
        $error = strtolower($error);

        $hint = match (true) {
            str_contains($error, 'connection refused'),
            str_contains($error, 'could not connect') => 'Nothing is listening on that host and port. From inside Docker, 127.0.0.1 is the container itself — use host.docker.internal to reach a relay on your machine.',
            str_contains($error, 'name or service not known'),
            str_contains($error, 'getaddrinfo') => 'MAIL_HOST does not resolve. Check the spelling, and that the container has DNS.',
            str_contains($error, 'authentication') => 'The relay rejected the credentials. Gmail and Outlook need an app password rather than the account password.',
            str_contains($error, 'ssl'),
            str_contains($error, 'tls'),
            str_contains($error, 'certificate') => 'A TLS negotiation problem. Port 587 wants MAIL_SCHEME=smtp (STARTTLS); port 465 wants MAIL_SCHEME=smtps.',
            str_contains($error, 'timed out') => 'The connection hung. Outbound SMTP is often blocked by hosting providers and home ISPs.',
            str_contains($error, 'sender address'),
            str_contains($error, 'from address') => 'The relay will not send as MAIL_FROM_ADDRESS. It usually has to be an address on a domain you have verified.',
            default => null,
        };

        if ($hint !== null) {
            $this->line('  <fg=cyan>Likely cause:</> '.$hint);
        }
    }

    /** The body of the test message. */
    private function body(string $mailer, string $sentAt): string
    {
        return implode("\n", [
            'This is a test message from '.config('app.name', 'Minizo').'.',
            '',
            'If you are reading it in an inbox, outgoing mail works — password',
            'resets and email verification will reach your users.',
            '',
            'Sent at:  '.$sentAt,
            'Mailer:   '.$mailer,
            'App URL:  '.config('app.url'),
            'From:     '.config('mail.from.address'),
        ]);
    }
}
