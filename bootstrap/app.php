<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Appended to the web group rather than registered as a named middleware,
         * so a deactivated account is caught on every authenticated page without
         * each route having to remember to opt in. It is a no-op for guests.
         *
         * SecurityHeaders is here for a related reason: it has to cover the public share
         * routes as well as the authenticated ones, and those are outside every auth
         * group. See the class for why an application is setting these at all.
         */
        $middleware->web(append: [
            EnsureUserIsActive::class,
            SecurityHeaders::class,
        ]);

        /*
         * Trust the reverse proxy most self-hosted installs sit behind.
         *
         * Off by default, because trusting X-Forwarded-* from an untrusted source lets a
         * caller spoof their own IP. Set TRUSTED_PROXIES to the proxy's address, or to '*'
         * when the container is only reachable through it.
         *
         * Leaving it unset has three consequences worth knowing: the public-share rate
         * limiter keys on the proxy's address, so every visitor shares one bucket;
         * X-Forwarded-Proto is ignored, so Laravel generates http:// URLs behind TLS; and
         * passkey login fails on an origin mismatch.
         */
        // env(), not config(): this closure runs before the configuration is loaded, so
        // config() throws here. Verified rather than assumed.
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        $proxies = (string) (env('TRUSTED_PROXIES') ?? '');

        if (filled($proxies)) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
