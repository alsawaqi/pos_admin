<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Loyalty refactor — rule lifecycle status. Mirror of
 * pos_merchant's LoyaltyRuleStatus.
 */
enum LoyaltyRuleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
