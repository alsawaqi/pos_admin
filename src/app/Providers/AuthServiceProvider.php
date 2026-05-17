<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\PlatformRole;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\User;
use App\Policies\CompanyDocumentPolicy;
use App\Policies\CompanyPolicy;
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
