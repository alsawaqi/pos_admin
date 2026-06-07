<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\User;

/**
 * Authorisation rules for the Merchant Portal Users management
 * tab on the Admin Portal (blueprint §4.5).
 *
 * `target` is the portal-user being acted on. We gate by the
 * existing MerchantUsers* permissions (already in PlatformPermission
 * and seeded for Super Admin + Onboarding Officer + Support).
 *
 * The Gate::before short-circuit for platform_super_admin still
 * applies, so these methods only run for non-superadmin roles.
 */
class PortalUserPolicy
{
    /**
     * List portal users for any merchant. Onboarding + Support +
     * Super Admin all get this.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(PlatformPermission::MerchantUsersView->value);
    }

    /**
     * Single-row read. Same gate as the list.
     */
    public function view(User $user, User $target): bool
    {
        return $user->can(PlatformPermission::MerchantUsersView->value);
    }

    /**
     * Dispatch a fresh welcome email for an existing portal user.
     * Requires the invite permission since it effectively replaces
     * the original invite.
     */
    public function invite(User $user): bool
    {
        return $user->can(PlatformPermission::MerchantUsersInvite->value);
    }

    /**
     * Suspend / reactivate / change branch scope. Gated by Revoke
     * because suspension is the canonical "shut this user out"
     * action a support agent or onboarding officer needs.
     */
    public function update(User $user, User $target): bool
    {
        return $user->can(PlatformPermission::MerchantUsersRevoke->value);
    }
}
