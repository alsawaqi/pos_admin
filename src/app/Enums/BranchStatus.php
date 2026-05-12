<?php

declare(strict_types=1);

namespace App\Enums;

enum BranchStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
