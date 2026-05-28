<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PlatformPermission::BranchesView->value);
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->can(PlatformPermission::BranchesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PlatformPermission::BranchesCreate->value);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->can(PlatformPermission::BranchesUpdate->value);
    }

    public function transitionStatus(User $user, Branch $branch): bool
    {
        return $user->can(PlatformPermission::BranchesTransitionStatus->value);
    }

    /**
     * Soft-delete a branch. Gated by BranchesDelete — separate from
     * Update because deleting cascades visibility everywhere
     * (merchant detail, device assignment, geofence checks) so it
     * deserves its own role-assignable permission.
     */
    public function delete(User $user, Branch $branch): bool
    {
        return $user->can(PlatformPermission::BranchesDelete->value);
    }
}
