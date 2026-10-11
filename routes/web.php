<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\TikTokController;
use App\Http\Controllers\UserApprovalController;
use Illuminate\Support\Facades\Route;

// Auth routes (public). Signed-in users hitting /login or /register go to the studio.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:5,1');
});

// Outside 'auth': logging out with an already-expired session must still land on /login
Route::post('/logout', [LogoutController::class, 'destroy'])->name('logout');

// TODO: Google OAuth via Socialite
// Route::get('/auth/google', [LoginController::class, 'redirectToGoogle'])->name('auth.google');
// Route::get('/auth/google/callback', [LoginController::class, 'handleGoogleCallback']);

// Protected routes
Route::middleware(['auth', 'approved'])->group(function () {
    // no-store: after logout the Back button must not show these pages from cache
    Route::middleware('cache.headers:no_store;private')->group(function () {
        Route::get('/', fn () => view('studio'))->name('home');
        Route::get('/autopilot', fn () => view('autopilot'))->name('autopilot');
        Route::get('/music', fn () => view('music'))->name('music');
        Route::get('/admin/users', [UserApprovalController::class, 'index'])->middleware('admin')->name('admin.users');
    });

    // Main admin creates accounts, approves sign-ups and can take access away again
    Route::post('/admin/users', [UserApprovalController::class, 'store'])->middleware('admin')->name('admin.users.store');
    Route::middleware('admin')->prefix('admin/users/{user}')->group(function () {
        Route::post('/approve', [UserApprovalController::class, 'approve'])->name('admin.users.approve');
        Route::post('/revoke', [UserApprovalController::class, 'revoke'])->name('admin.users.revoke');
        Route::delete('/', [UserApprovalController::class, 'reject'])->name('admin.users.reject');
    });

    // TikTok OAuth (redirect URI registered on the TikTok app → /tiktok/callback)
    Route::get('/tiktok/connect', [TikTokController::class, 'connect'])->name('tiktok.connect');
    Route::get('/tiktok/callback', [TikTokController::class, 'callback'])->name('tiktok.callback');
});
