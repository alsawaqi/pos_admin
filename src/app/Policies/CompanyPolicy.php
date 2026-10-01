<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use App\Models\Company;
use App\Models\User;
use App\Support\TenantContext;
use Spatie\Permission\PermissionRegistrar;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PlatformPermission::MerchantsView->value);
    }

    public function view(User $user, Company $company): bool
    {
        return $user->can(PlatformPermission::MerchantsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PlatformPermission::MerchantsCreate->value);
    }

    public function update(User $user, Company $company): bool
    {
        return $user->can(PlatformPermission::MerchantsUpdate->value);
    }

    public function transitionStatus(User $user, Company $company): bool
    {
        return $user->can(PlatformPermission::MerchantsTransitionStatus->value);
    }

    /**
     * Reopening a closed (Inactive) merchant — owner decision 2026-10-01:
     * a Super Admin only. No permission grants it, so a role that holds
     * merchants.transition_status (or even every permission) still
     * cannot. Super Admins normally pass through the Gate::before hook in
     * AuthServiceProvider; the role check here keeps the rule true on
     * its own.
     */
    public function reopen(User $user, Company $company): bool
    {
        if (! $user->isPlatformAdmin()) {
            return false;
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

        try {
            return $user->hasRole(PlatformRole::SuperAdmin->value);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    public function manageActivities(User $user, Company $company): bool
    {
        return $user->can(PlatformPermission::MerchantsUpdate->value);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->can(PlatformPermission::MerchantsDelete->value);
    }
}
