<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\PlatformRole;
use App\Models\Advertiser;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BusinessActivity;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\ContentAsset;
use App\Models\Device;
use App\Models\DeviceMake;
use App\Models\DeviceModel;
use App\Models\MarketingSlider;
use App\Models\User;
use App\Policies\AdvertiserPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\BranchPolicy;
use App\Policies\ContentAssetPolicy;
use App\Policies\MarketingSliderPolicy;
use App\Policies\BusinessActivityPolicy;
use App\Policies\CompanyDocumentPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\DeviceMakePolicy;
use App\Policies\DeviceModelPolicy;
use App\Policies\DevicePolicy;
use App\Policies\PortalUserPolicy;
use App\Support\TenantContext;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Company::class => CompanyPolicy::class,
        CompanyDocument::class => CompanyDocumentPolicy::class,
        Branch::class => BranchPolicy::class,
        Device::class => DevicePolicy::class,
        DeviceMake::class => DeviceMakePolicy::class,
        DeviceModel::class => DeviceModelPolicy::class,
        BusinessActivity::class => BusinessActivityPolicy::class,
        Advertiser::class => AdvertiserPolicy::class,
        ContentAsset::class => ContentAssetPolicy::class,
        MarketingSlider::class => MarketingSliderPolicy::class,
        // Audit log viewer (blueprint §4.7). Read-only model; the
        // policy only defines viewAny + view + export — see
        // AuditLogPolicy docblock for the rationale.
        AuditLog::class => AuditLogPolicy::class,
        // PortalUserPolicy is registered against User::class because
        // merchant portal users and platform admins share the same
        // pos_users table (differentiated by user_type). The policy
        // method names (invite, resend, suspend) are merchant-portal
        // specific — admin self-management lives behind dedicated
        // PlatformUsers* permissions, not this policy.
        User::class => PortalUserPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
        $this->grantSuperAdminEverything();
        $this->registerRecallerScopedGuard();
    }

    /**
     * Register the 'pos_admin_session' guard driver — a byte-for-byte copy of the
     * framework's session driver ({@see \Illuminate\Auth\AuthManager::createSessionDriver})
     * except it overrides the remember-me cookie name. pos_admin and pos_merchant
     * share one APP_KEY and the pos_users table, so with the stock driver both apps
     * derive the SAME recaller cookie name (remember_web_<sha1(SessionGuard)>) and a
     * merchant's "remember me" cookie could auto-authenticate into pos_admin's web
     * guard — bypassing the login-time user_type gate — the moment SESSION_DOMAIN is
     * ever widened past host-only. Giving each app a distinct recaller name closes
     * that latent escalation while keeping the guard KEY 'web' (Spatie + all
     * Auth::guard('web') call sites untouched).
     */
    private function registerRecallerScopedGuard(): void
    {
        Auth::extend('pos_admin_session', function ($app, string $name, array $config) {
            $guard = new class(
                $name,
                Auth::createUserProvider($config['provider'] ?? null),
                $app['session.store'],
                rehashOnLogin: (bool) $app['config']->get('hashing.rehash_on_login', true),
                timeboxDuration: (int) $app['config']->get('auth.timebox_duration', 200000),
                hashKey: (string) $app['config']->get('app.key'),
            ) extends SessionGuard {
                public function getRecallerName(): string
                {
                    return 'remember_pos_admin_web';
                }
            };

            $guard->setCookieJar($app['cookie']);
            $guard->setDispatcher($app['events']);
            $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

            if (isset($config['remember'])) {
                $guard->setRememberDuration($config['remember']);
            }

            return $guard;
        });
    }

    /**
     * Platform super admin must always have every permission, including any
     * future permissions added to {@see \App\Enums\PlatformPermission} that
     * have not yet been re-seeded onto the role pivot. Running the check on
     * the platform team id avoids accidental matches against a same-named
     * role scoped to a real merchant team.
     */
    private function grantSuperAdminEverything(): void
    {
        Gate::before(static function (Authenticatable $user): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            $previousTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();
            app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

            try {
                return $user->hasRole(PlatformRole::SuperAdmin->value) ? true : null;
            } finally {
                app(PermissionRegistrar::class)->setPermissionsTeamId($previousTeamId);
            }
        });
    }
}
