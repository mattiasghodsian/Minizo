<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    /*
     * One Settings screen, matching the design's card grid.
     *
     * It is NOT behind `password.confirm`. The security page used to be, but merging the
     * three tabs into one screen would have put a password prompt in front of changing your
     * own name. The two cards that genuinely need the gate — 2FA and passkeys — ask for it
     * themselves and refuse to act without it, which is also what Fortify's per-feature
     * `confirmPassword` option is for. See resources/views/pages/settings/index.blade.php.
     */
    Route::livewire('settings', 'pages::settings.index')->name('settings.edit');

    /*
     * The old tab routes, kept as redirects rather than deleted.
     *
     * `security.edit` is not dead weight: the .well-known/passkey-endpoints document below
     * publishes it as this site's passkey enrolment and management URL, and a browser or
     * password manager that has cached that document will keep following it. A 302 to the
     * real screen is what keeps those clients working.
     *
     * `profile.edit` and `appearance.edit` are here for the humans — anyone with an old
     * bookmark or an open tab.
     */
    Route::redirect('settings/profile', '/settings')->name('profile.edit');
    Route::redirect('settings/security', '/settings')->name('security.edit');
    Route::redirect('settings/appearance', '/settings')->name('appearance.edit');
});

/*
 * There is deliberately no settings/sharing route.
 *
 * The instance-wide public-sharing switch lives on the Users screen, where the prototype
 * draws it. Settings is about your own account; "may anyone here publish public links" is a
 * decision about other people, which is what the Users screen is for. The handoff's written
 * spec says Settings, but the finished prototype disagrees with it and is the better guide.
 */

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
