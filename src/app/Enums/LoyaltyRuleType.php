<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Loyalty refactor — rule mechanic (blueprint §5.8). Mirror of
 * pos_merchant's LoyaltyRuleType so the admin read-only model can
 * cast the column.
 */
enum LoyaltyRuleType: string
{
    case VisitBased = 'visit_based';
    case SpendBased = 'spend_based';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
