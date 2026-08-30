<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /** Sign out an account that has been disabled since it logged in. */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user !== null && ! $user->is_active) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = __('Your account has been deactivated.');

            if ($request->expectsJson()) {
                abort(Response::HTTP_FORBIDDEN, $message);
            }

            return redirect()->route('login')->withErrors([
                // Keyed to the login form's own field so the message lands in the
                // right place instead of an unrendered generic error bag.
                'email' => $message,
            ]);
        }

        return $next($request);
    }
}
