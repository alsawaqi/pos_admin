<?php

declare(strict_types=1);

namespace App\Enums;

enum UserType: string
{
    case PlatformAdmin = 'platform_admin';
    case Merchant = 'merchant';
}
