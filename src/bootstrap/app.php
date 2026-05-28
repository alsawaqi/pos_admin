<?php

use App\Http\Middleware\AttachSentryContext;
use App\Http\Middleware\EnsurePosAdminSessionIsFresh;
use App\Http\Middleware\PreventBackHistoryCache;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(__DIR__.'/../routes/admin.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->append(PreventBackHistoryCache::class);

        $middleware->alias([
            'pos.admin.session' => EnsurePosAdminSessionIsFresh::class,
            'pos.tenant' => SetTenantContext::class,
        ]);

        $middleware->web(append: [
            SetTenantContext::class,
            // Sprint 3 — must run AFTER SetTenantContext so the
            // tenant id it stamps on the Sentry scope is populated.
            // No-op when SENTRY_LARAVEL_DSN is unset.
            AttachSentryContext::class,
        ]);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/admin');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry capture for unhandled exceptions (Sprint 3 hardening).
        // The integration is a no-op when SENTRY_LARAVEL_DSN is empty,
        // so this is safe to leave on in every environment — dev just
        // doesn't ship anywhere, prod with a DSN actually reports.
        // Per-tenant + per-user context is attached by
        // {@see \App\Http\Middleware\AttachSentryContext} during the
        // request lifecycle.
        \Sentry\Laravel\Integration::handles($exceptions);
    })->create();
