<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\SpaController;
use App\Http\Middleware\EnsurePosAdminSessionIsFresh;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequireJsonRequest;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routing strategy
|--------------------------------------------------------------------------
|
| Middleware on these routes is declared with FQCN classes rather than
| aliases so the guards cannot be silently disabled by a missing alias,
| stale config cache, or a future rename. SpaController carries an extra
| in-body auth check as a belt-and-braces safety net.
|
| Public + guest-only:
|   GET  /              -> permanent redirect to /admin
|   GET  /login         -> SPA shell, redirects to /admin if already authed
|   POST /auth/login    -> issue session + JWT
|
| Authenticated:
|   GET  /admin/{*}     -> SPA shell, requires auth + fresh session
|   GET  /auth/user     -> JSON only, current user payload
|   POST /auth/logout   -> destroy session + cookie
*/

Route::redirect('/', '/admin');

Route::middleware(RedirectIfAuthenticated::class)->group(function (): void {
    Route::get('/login', SpaController::class)
        ->name('login');

    Route::post('/auth/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:pos-admin-login')
        ->name('auth.login');
});

Route::middleware([Authenticate::class.':web', EnsurePosAdminSessionIsFresh::class])->group(function (): void {
    Route::get('/admin/{path?}', SpaController::class)
        ->where('path', '.*')
        ->name('admin.dashboard');

    Route::get('/auth/user', [AuthenticatedSessionController::class, 'show'])
        ->middleware(RequireJsonRequest::class)
        ->name('auth.user');
});

Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware(Authenticate::class.':web')
    ->name('auth.logout');
