<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6 backfill — expense category (blueprint §5.10).
 *
 * Mirror of pos_merchant's ExpenseCategory so the admin app's
 * read-only Expense model can cast the column. Kept in sync by
 * hand (both apps share the pos_expenses table).
 */
enum ExpenseCategory: string
{
    case Utilities = 'utilities';
    case Supplies = 'supplies';
    case Maintenance = 'maintenance';
    case Salaries = 'salaries';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
