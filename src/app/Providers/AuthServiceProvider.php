<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BusinessActivity;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Device;
use App\Models\DeviceMake;
use App\Models\DeviceModel;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\BranchPolicy;
use App\Policies\BusinessActivityPolicy;
use App\Policies\CompanyDocumentPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\DeviceMakePolicy;
use App\Policies\DeviceModelPolicy;
use App\Policies\DevicePolicy;
use App\Policies\PortalUserPolicy;
use App\Support\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
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
