<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Loyalty refactor — transaction kind (blueprint §10.6). Mirror
 * of pos_merchant's LoyaltyTransactionType.
 */
enum LoyaltyTransactionType: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case Adjust = 'adjust';
    case Expire = 'expire';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
