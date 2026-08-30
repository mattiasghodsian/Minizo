<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UserSessions
{
    /**
     * Drop a user's stored sessions, optionally keeping one.
     *
     * Auth::logoutOtherDevices() is the framework's answer to this, but it only marks the
     * password hash in the session and relies on the AuthenticateSession middleware to act
     * on it - and that middleware is not in this app's stack. Deleting the rows works
     * regardless, because the session driver reads them on every request.
     *
     * @param  string|null  $except  a session id to leave alone, usually the caller's own
     */
    public static function purge(User $user, ?string $except = null): void
    {
        // Only meaningful for the database driver; the others have no table to sweep, and
        // a missing table must not turn the operation that called this into an error.
        if (config('session.driver') !== 'database') {
            return;
        }

        try {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($except !== null, fn ($query) => $query->where('id', '!=', $except))
                ->delete();
        } catch (Throwable) {
            // The operation that called this has already succeeded; a failed sweep only
            // narrows the window rather than closing it.
        }
    }
}
