<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6b — closed enum mirror for the merchant app's
 * WalletLedgerEntryType. pos_admin reads only.
 */
enum WalletLedgerEntryType: string
{
    case TopUp = 'topup';
    case RedemptionUse = 'redemption_use';
    case Adjustment = 'adjustment';
    case RefundIn = 'refund_in';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
