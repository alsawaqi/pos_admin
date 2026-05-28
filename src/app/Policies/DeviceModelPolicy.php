<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\DeviceModel;
use App\Models\User;

/**
 * Authorisation rules for the Device Models catalogue (Settings →
 * Device catalogue, models pane).
 *
 * Same shape as {@see DeviceMakePolicy}: anyone can list (Register
 * Device dropdown), only DeviceModelsManage can mutate.
 */
class DeviceModelPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DeviceModel $model): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(PlatformPermission::DeviceModelsManage->value);
    }

    public function update(User $user, DeviceModel $model): bool
    {
        return $user->can(PlatformPermission::DeviceModelsManage->value);
    }

    public function delete(User $user, DeviceModel $model): bool
    {
        return $user->can(PlatformPermission::DeviceModelsManage->value);
    }
}
