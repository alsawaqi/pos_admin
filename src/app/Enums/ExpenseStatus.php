<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6 backfill — expense review status (blueprint §5.10).
 *
 * Mirror of pos_merchant's ExpenseStatus so the admin app's
 * read-only Expense model can cast the column.
 */
enum ExpenseStatus: string
{
    case Recorded = 'recorded';
    case Reviewed = 'reviewed';
    case Rejected = 'rejected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
