<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\DeviceMake;
use App\Models\User;

/**
 * Authorisation rules for the Device Makes catalogue (Settings →
 * Device catalogue, makes pane).
 *
 * Listing is OPEN to any authenticated admin — the Register Device
 * form has to populate its Make dropdown for everyone with the
 * Devices.Register permission, not only those allowed to manage the
 * catalogue itself. Mutations are gated by DeviceModelsManage —
 * the same key covers makes and models since they're a single
 * conceptual feature in the UI.
 *
 * (The catch-all "platform_super_admin → true" Gate::before runs
 * before any of these methods, so Super Admin always passes.)
 */
class DeviceMakePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DeviceMake $make): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(PlatformPermission::DeviceModelsManage->value);
    }

    public function update(User $user, DeviceMake $make): bool
    {
        return $user->can(PlatformPermission::DeviceModelsManage->value);
    }

    public function delete(User $user, DeviceMake $make): bool
    {
        return $user->can(PlatformPermission::DeviceModelsManage->value);
    }
}
