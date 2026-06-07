<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\BusinessActivity;
use App\Models\User;

/**
 * Authorisation rules for the platform-wide list of business
 * activities (the reference catalogue merchants pick from when
 * onboarding).
 *
 * Listing is intentionally NOT gated — anyone who can view merchants
 * needs to be able to read the available activities to render the
 * merchant create wizard. The mutating endpoints (create/update/
 * delete) require the BusinessActivitiesManage permission.
 */
class BusinessActivityPolicy
{
    /**
     * Listing is open to any authenticated admin — the catalogue is
     * pre-populated and the onboarding flow needs it. No permission
     * check here. Returning true keeps the policy gate happy when
     * the controller calls $this->authorize('viewAny', ...).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BusinessActivity $activity): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(PlatformPermission::BusinessActivitiesManage->value);
    }

    public function update(User $user, BusinessActivity $activity): bool
    {
        return $user->can(PlatformPermission::BusinessActivitiesManage->value);
    }

    public function delete(User $user, BusinessActivity $activity): bool
    {
        return $user->can(PlatformPermission::BusinessActivitiesManage->value);
    }
}
