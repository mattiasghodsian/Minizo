<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /** Resolve the bearer token from Settings to its user. */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) ($request->bearerToken() ?? '');

        // One message for both a missing and an unknown token, so a reply cannot
        // be used to tell which strings are live.
        $user = blank($plain) ? null : ApiToken::resolve($plain);

        abort_if($user === null, Response::HTTP_UNAUTHORIZED, __('API token missing or invalid.'));

        // EnsureUserIsActive is web-group only and needs a session; it cannot run here.
        abort_if(! $user->is_active, Response::HTTP_FORBIDDEN, __('Your account has been deactivated.'));

        Auth::setUser($user);

        $this->touch($user);

        return $next($request);
    }

    /** Stamp last-used at most once a minute, so a polling client is not a write per request. */
    private function touch(User $user): void
    {
        $lastUsed = $user->api_token_last_used_at;

        if ($lastUsed !== null && $lastUsed->greaterThan(now()->subMinute())) {
            return;
        }

        $user->forceFill(['api_token_last_used_at' => now()])->save();
    }
}
