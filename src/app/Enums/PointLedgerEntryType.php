<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6b — closed enum mirror for the merchant app's
 * PointLedgerEntryType. pos_admin only reads the ledger
 * (for cross-merchant reporting + support); writes happen
 * on the merchant side.
 */
enum PointLedgerEntryType: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case Adjustment = 'adjustment';
    case RefundIn = 'refund_in';
    case Expiry = 'expiry';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
