<?php

use App\Http\Controllers\Api\FeedController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

/*
 * The read-only feed API, for consuming your own feed in your own apps.
 *
 * Outside the web group on purpose: a bearer-token API must not accept a browser's
 * session cookie, which would also make every request CSRF-relevant. The token comes
 * from the API card on the Settings screen.
 *
 * Deliberately just the feed. Following, searching and the library stay web-only.
 */
Route::middleware([AuthenticateApiToken::class, 'throttle:minizo-api'])->group(function () {
    Route::get('feed', FeedController::class)->name('api.feed');
});
