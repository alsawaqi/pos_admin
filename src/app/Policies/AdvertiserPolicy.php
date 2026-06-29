<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\Advertiser;
use App\Models\User;

/**
 * Authorisation for admin-driven advertiser onboarding. Unlike the business
 * activity catalogue (whose listing is open so the merchant wizard works), the
 * advertiser list is sensitive — every method requires the
 * MarketingAdvertisersManage permission. Super admins pass via the
 * Gate::before short-circuit in AuthServiceProvider.
 */
class AdvertiserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PlatformPermission::MarketingAdvertisersManage->value);
    }

    public function view(User $user, Advertiser $advertiser): bool
    {
        return $user->can(PlatformPermission::MarketingAdvertisersManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PlatformPermission::MarketingAdvertisersManage->value);
    }

    public function update(User $user, Advertiser $advertiser): bool
    {
        return $user->can(PlatformPermission::MarketingAdvertisersManage->value);
    }

    public function delete(User $user, Advertiser $advertiser): bool
    {
        return $user->can(PlatformPermission::MarketingAdvertisersManage->value);
    }
}
