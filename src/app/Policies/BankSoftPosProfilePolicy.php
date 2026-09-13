<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\User;

class BankSoftPosProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PlatformPermission::DevicesControl->value);
    }

    public function manage(User $user): bool
    {
        return $user->can(PlatformPermission::DevicesControl->value);
    }
}
