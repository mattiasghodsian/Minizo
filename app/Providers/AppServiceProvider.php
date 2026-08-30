<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /** Register any application services. */
    public function register(): void
    {
        //
    }

    /** Bootstrap any application services. */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureHttps();
        $this->configureDevQueue();
        $this->configureRateLimiters();
    }

    /** Named limiters. */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('public-share', fn (Request $request) => Limit::perMinute(
            (int) config('minizo.shares.rate_limit', 60)
        )->by($request->ip() ?? 'unknown'));

        // The Tidal request budget, consumed by SyncArtistReleasesJob's RateLimited
        // middleware. Instance-wide rather than per-artist, and a throttled job is
        // released back to the queue rather than sleeping in a worker.
        RateLimiter::for('tidal', fn () => Limit::perMinute(
            (int) config('minizo.feed.requests_per_minute', 60)
        ));
    }

    /** Teach `composer dev` about the downloads queue. */
    protected function configureDevQueue(): void
    {
        $queue = (string) config('minizo.downloads.queue', 'downloads');

        DevCommands::artisan("queue:listen --queue=default,{$queue} --tries=1 --timeout=0", 'queue');
    }

    /** Force HTTPS when configured, so URL generation matches the scheme the browser actually used. WebAuthn rejects a ceremony whose origin does not match exactly, scheme and port included. */
    protected function configureHttps(): void
    {
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }
    }

    /** Configure default behaviors for production-ready applications. */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
