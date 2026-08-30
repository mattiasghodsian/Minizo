<?php

use App\Http\Controllers\LibraryCoverController;
use App\Http\Controllers\PublicShareController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Minizo has no public landing page: the front door is the login screen, and
// anyone already signed in goes straight to the Download screen.
Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('download')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    /*
     * Download is the home screen. The design has six authenticated screens and no
     * "dashboard", so the placeholder is replaced outright rather than kept
     * alongside — one canonical landing page, not two.
     *
     * Renaming the route touched every place that hardcoded the old name,
     * including two literal '/dashboard' strings inside the passkey component and
     * Fortify's own `home` config.
     */
    Route::livewire('download', 'pages::download')->name('download');

    /*
     * The library. `directory` is a folder NAME, not a path — the library is one
     * level deep. The regex keeps separators and dot-segments out of the parameter
     * so a traversal attempt never reaches the component; FolderService::find()
     * then resolves it against the real folder list and 404s on anything unknown.
     */
    Route::livewire('files/{directory}', 'pages::files')
        ->where('directory', '[^/\\\\]+')
        ->name('files');

    /*
     * A file's embedded cover art.
     *
     * Its own endpoint so a listing does no disk work: the browser fetches covers lazily
     * for the rows it can see, and a 404 — the normal answer for an untagged file — leaves
     * the generated tile showing. Both segments are folder/file NAMES, matched against the
     * real listing rather than joined onto a path.
     */
    Route::get('files/{directory}/cover/{filename}', LibraryCoverController::class)
        ->where(['directory' => '[^/\\\\]+', 'filename' => '[^/\\\\]+'])
        ->name('files.cover');

    /*
     * The Feed. No permission gate — following an artist affects nobody else, and the
     * screen degrades to an explanatory empty state when Tidal is unconfigured. The only
     * admin-only part is the row of preview pills, gated inside the component by the
     * preview-other-users ability.
     */
    Route::livewire('feed', 'pages::feed')->name('feed');

    /*
     * The audit screen for public links. Not admin-only: the design's "LINKS BY" row
     * starts with "All users", so anyone holding the Share permission can see who
     * published what. SharePolicy::viewAny checks granted() rather than effective(),
     * because turning the kill-switch off must not hide the tool for finding links that
     * are still live.
     */
    Route::livewire('shares', 'pages::shares')->name('shares');

    /*
     * Accounts, roles, folder access and permissions — the sixth authenticated screen.
     *
     * Admin-only, enforced by the component's own authorize('viewAny', User::class) rather
     * than a middleware, so the 403 comes from the policy that owns the decision. It also
     * carries the instance-wide sharing switch, which the prototype places here rather than
     * in Settings: this is the screen about what OTHER people may do.
     */
    Route::livewire('users', 'pages::users')->name('users');
});

/*
 * ---------------------------------------------------------------------------
 * Public share links — the only unauthenticated surface that serves library
 * content.
 * ---------------------------------------------------------------------------
 *
 * Outside every auth group, and deliberately outside `verified` too: the visitor is a
 * stranger with a URL, not an account.
 *
 * Throttled per IP. The token is ~71 bits of randomness, so guessing is not the
 * threat — the throttle is there because these routes stream files, and an unthrottled
 * public endpoint that reads gigabytes off disk is a way to exhaust a self-hosted box
 * whether or not the caller ever finds a valid token.
 */
Route::middleware('throttle:public-share')->group(function () {
    Route::get('s/{token}', [PublicShareController::class, 'show'])->name('share.show');

    Route::get('s/{token}/download', [PublicShareController::class, 'download'])
        ->name('share.download');

    /*
     * The shared track's own embedded artwork. Track shares only — a folder share is many
     * files with potentially many covers, and choosing one to represent the folder would be
     * a guess, so the design's generated tile stays there.
     */
    Route::get('s/{token}/cover', [PublicShareController::class, 'cover'])->name('share.cover');

    /*
     * One track out of a folder share. The filename is matched against the share's own
     * listing rather than resolved against the disk, so the regex here is defence in
     * depth rather than the actual guard.
     */
    Route::get('s/{token}/download/{filename}', [PublicShareController::class, 'track'])
        ->where('filename', '[^/\\\\]+')
        ->name('share.download.track');
})->whereAlphaNumeric('token');

/*
 * The /_ui design-system gallery lived here and is gone.
 *
 * It existed to build the primitives against the prototype and to catch regressions in them
 * while the screens were still being written. Every primitive now appears on a real screen
 * with a real test, so the gallery had become a second place to update whenever one changed —
 * and a stale gallery is worse than none, because it looks authoritative.
 */

require __DIR__.'/settings.php';
