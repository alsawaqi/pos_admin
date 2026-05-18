<?php

namespace App\Providers;

use App\Models\Sanctum\PersonalAccessToken;
use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     *
     * Note: there used to be a RateLimiter::for('pos-admin-login') named
     * limiter registered here. It moved into the controller via
     * {@see \App\Http\Requests\Auth\LoginRequest::ensureIsNotRateLimited()}
     * so successful logins no longer consume the quota.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
