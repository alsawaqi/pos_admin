<?php

declare(strict_types=1);

namespace App\Enums;

enum CompanyStatus: string
{
    case Onboarding = 'onboarding';
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
}
