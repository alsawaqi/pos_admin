<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', SpaController::class)
        ->name('login');

    Route::post('/auth/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:pos-admin-login')
        ->name('auth.login');
});

Route::middleware(['auth', 'pos.admin.session'])->group(function (): void {
    Route::get('/admin/{path?}', SpaController::class)
        ->where('path', '.*')
        ->name('admin.dashboard');

    Route::get('/auth/user', [AuthenticatedSessionController::class, 'show'])
        ->name('auth.user');
});

Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('auth.logout');
