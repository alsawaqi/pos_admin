<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceStatus: string
{
    case Registered = 'registered';
    case Assigned = 'assigned';
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';
}
