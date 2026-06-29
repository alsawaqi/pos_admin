<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\MarketingSlider;
use App\Models\User;

/**
 * Authorisation for the slider builder. The whole surface (list / view / build /
 * edit / delete) requires marketing.sliders.manage. Super admins pass via the
 * Gate::before short-circuit in AuthServiceProvider.
 */
class MarketingSliderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PlatformPermission::MarketingSlidersManage->value);
    }

    public function view(User $user, MarketingSlider $slider): bool
    {
        return $user->can(PlatformPermission::MarketingSlidersManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PlatformPermission::MarketingSlidersManage->value);
    }

    public function update(User $user, MarketingSlider $slider): bool
    {
        return $user->can(PlatformPermission::MarketingSlidersManage->value);
    }

    public function delete(User $user, MarketingSlider $slider): bool
    {
        return $user->can(PlatformPermission::MarketingSlidersManage->value);
    }
}
