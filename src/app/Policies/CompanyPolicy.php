<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\Company;
use App\Models\User;

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

    public function manageActivities(User $user, Company $company): bool
    {
        return $user->can(PlatformPermission::MerchantsUpdate->value);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->can(PlatformPermission::MerchantsDelete->value);
    }
}
