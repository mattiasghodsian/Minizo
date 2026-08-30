<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers a web server would normally add.
 *
 * Normally these belong in nginx or Caddy and an application has no business setting
 * them. Minizo serves HTTP from `artisan serve` inside the container, so there is no web
 * server in the image to put them in - public/.htaccess is dead weight, since nothing
 * reads it. Without this middleware the app ships none of them.
 */
class SecurityHeaders
{
    /**
     * Sources a page may load from.
     *
     * 'unsafe-inline' is present on both script and style, and removing it is not a small
     * change: Livewire, Alpine and Flux all emit inline handlers and inline style, and the
     * Flux appearance directive runs an inline script before first paint on purpose, to
     * avoid a light/dark flash. A nonce-based policy would mean rewriting all of that.
     *
     * img-src allows https: because cover art legitimately comes from third parties -
     * Tidal's CDN on the Feed, the Cover Art Archive in the metadata editor.
     */
    private const POLICY = [
        'default-src' => "'self'",
        'script-src' => "'self' 'unsafe-inline' 'unsafe-eval'",
        'style-src' => "'self' 'unsafe-inline'",
        'img-src' => "'self' data: https:",
        'font-src' => "'self' data:",
        'connect-src' => "'self'",
        'media-src' => "'self'",
        'object-src' => "'none'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'self'",
    ];

    /**
     * The policy, relaxed for asset origins while the Vite dev server is running.
     *
     * In development the bundle, its HMR socket and the fonts are all served from Vite's
     * own origin, so a policy of 'self' blocks every one of them. Naming that origin
     * explicitly does not work either: **CSP source expressions cannot express an IPv6
     * literal**, and Vite binds to [::1] here - `http://[::1]:5174` matches nothing, which
     * is exactly how the fonts stayed blocked after the host was added.
     *
     * So in dev the asset directives fall back to the `http:`/`https:`/`ws:` scheme
     * sources. That is permissive, and it is meant to be: with a dev server on an
     * arbitrary origin there is no policy that both protects and works. Production serves
     * the built assets from this origin, never takes this branch, and keeps the real
     * policy - which is the one that matters, since it is the deployed app an attacker
     * would reach.
     */
    private function policy(): string
    {
        $directives = self::POLICY;

        if (Vite::isRunningHot()) {
            $directives['script-src'] .= ' http: https:';
            $directives['style-src'] .= ' http: https:';
            $directives['font-src'] .= ' http: https:';
            $directives['connect-src'] .= ' http: https: ws: wss:';
        }

        return collect($directives)
            ->map(fn (string $value, string $key): string => $key.' '.$value)
            ->implode('; ');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // nosniff matters more here than usual: both cover endpoints stream bytes lifted
        // out of a FLAC, and a crafted "image" that sniffs as HTML would run on this
        // origin.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // The Users screen drives role and permission changes from wire:click, which is
        // the shape clickjacking targets. frame-ancestors above is the modern equivalent;
        // this is for anything that still only understands the old header.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        /*
         * The share URL IS the secret - anyone holding it can read the folder behind it,
         * with no account. Without a policy, following any outbound link from a share page
         * hands the whole URL, token included, to the destination in the Referer header.
         */
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $response->headers->set('Content-Security-Policy', $this->policy());

        // Only over TLS. Sent on a plain-HTTP request it would pin a browser to a scheme
        // the instance may not serve, and a self-hosted box on a LAN often does not.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
