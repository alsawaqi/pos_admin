<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Order types a branch can take. Drives the segmented control on the
 * Main POS top bar (blueprint §6.3 / §6.4) and the default selection
 * when an operator opens a new ticket.
 */
enum BranchOrderType: string
{
    case Quick = 'quick';
    case DineIn = 'dine_in';
    case ToGo = 'to_go';
    case Delivery = 'delivery';
    case Car = 'car';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
