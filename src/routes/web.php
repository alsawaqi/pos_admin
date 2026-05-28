<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CsrfTokenController;
use App\Http\Controllers\SpaController;
use App\Http\Middleware\EnsurePosAdminSessionIsFresh;
use App\Http\Middleware\EnsureUserIsAuthenticated;
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
| Public:
|   GET  /              -> permanent redirect to /admin
|   GET  /auth/csrf     -> refresh CSRF token (XHR only)
|
| Guest-only:
|   GET  /login         -> SPA shell, redirects to /admin if already authed
|   POST /auth/login    -> issue session + JWT
|
| Authenticated:
|   GET  /admin/{*}     -> SPA shell, redirects to /login if not authed
|   GET  /auth/user     -> JSON only, current user payload
|   POST /auth/logout   -> destroy session + cookie
*/

Route::redirect('/', '/admin');

Route::get('/auth/csrf', CsrfTokenController::class)
    ->middleware(RequireJsonRequest::class)
    ->name('auth.csrf');

Route::middleware(RedirectIfAuthenticated::class)->group(function (): void {
    Route::get('/login', SpaController::class)
        ->name('login');
});

// POST /auth/login intentionally stays OUT of the guest guard so the
// controller can gracefully handle a request from a browser that still
// holds a valid session cookie (e.g. double-click, stale tab, or after
// the SPA's auth state was cleared but the server cookie wasn't).
//
// Rate limiting lives inside the controller now (LoginRequest::
// ensureIsNotRateLimited + manual RateLimiter::hit on failure) so
// successful logins do not consume the quota — see Laravel Breeze
// pattern. The route therefore drops the legacy throttle:pos-admin-login
// middleware to avoid double-counting.
Route::post('/auth/login', [AuthenticatedSessionController::class, 'store'])
    ->name('auth.login');

// /admin/* uses our project-owned guard. EnsureUserIsAuthenticated keeps
// the contract visible right next to the route AND adds the browser-cache-
// defeating headers (Cache-Control: no-store + Vary: Cookie) that prevent
// a previously rendered /admin from being shown after logout.
Route::middleware([EnsureUserIsAuthenticated::class, EnsurePosAdminSessionIsFresh::class])->group(function (): void {
    // The SPA fallback intentionally EXCLUDES `/admin/api/*` so that
    // a GET to an API endpoint resolves against the controllers
    // registered in routes/admin.php instead of silently serving the
    // SPA shell. Without this, missing/forbidden GET routes returned
    // the SPA HTML (200) instead of the proper 403/404/JSON response.
    Route::get('/admin/{path?}', SpaController::class)
        ->where('path', '^(?!api(/|$)).*')
        ->name('admin.dashboard');

    Route::get('/auth/user', [AuthenticatedSessionController::class, 'show'])
        ->middleware(RequireJsonRequest::class)
        ->name('auth.user');
});

Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware(Authenticate::class.':web')
    ->name('auth.logout');
