<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'get'])->name('dashboard');
    Route::post('/dashboard', [DashboardController::class, 'post'])->name('dashboard');
});

Route::middleware('auth')->group(function () {
    // Profile routes
    Route::controller(ProfileController::class)->group(function () {
        Route::get('/profile', 'edit')->name('profile.edit');
        Route::patch('/profile', 'update')->name('profile.update');
        Route::delete('/profile', 'destroy')->name('profile.destroy');
    });

    // Library routes
    Route::controller(LibraryController::class)->group(function () {
        Route::get('/lib/{directory}', 'view')->name('library');
        Route::delete('/lib/{directory}', 'destroy')->name('library.destroy');
        Route::post('/library/move', 'move')->name('library.move');
    });
});

require __DIR__.'/auth.php';
