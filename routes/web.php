<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\SsoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/sso/login', [SsoController::class, 'login'])->name('sso.login');

    Route::redirect('/auth/google/redirect', '/sso/login')->name('auth.google.redirect');
    Route::redirect('/auth/google/callback', '/sso/login')->name('auth.google.callback');
});

Route::get('/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');

Route::middleware('auth')->group(function (): void {
    Route::get('/sso/session-check/start', [SsoController::class, 'startSessionCheck'])->name('sso.session-check.start');
    Route::get('/sso/session-check/callback', [SsoController::class, 'completeSessionCheck'])->name('sso.session-check.callback');
});

Route::get('/sso/frontchannel-logout', [SsoController::class, 'frontchannelLogout'])
    ->name('sso.frontchannel-logout');

Route::post('/auth/logout', [GoogleAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('auth.logout');
