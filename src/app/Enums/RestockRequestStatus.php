<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 5c — restock-request lifecycle states.
 * Mirror of pos_merchant's enum.
 */
enum RestockRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Fulfilled = 'fulfilled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
