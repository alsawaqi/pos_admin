<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;

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
     * Historical notes:
     *   - The named RateLimiter::for('pos-admin-login') limiter moved into
     *     {@see \App\Http\Requests\Auth\LoginRequest::ensureIsNotRateLimited()}
     *     so successful logins do not consume the quota (Breeze pattern).
     *   - The Sanctum::usePersonalAccessTokenModel() override was removed
     *     when we collapsed the pos_admin_personal_access_tokens table
     *     into the shared default `personal_access_tokens` table that
     *     lives in the charity DB. Sanctum's default model + table apply
     *     and Sanctum's own tokenable_type column keeps our tokens
     *     distinct from any other app sharing the database.
     */
    public function boot(): void
    {
        //
    }
}
