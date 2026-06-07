<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Parties in a per-merchant commission split.
 *
 * Platform/Bank/Other are the configurable share LINES the admin types a
 * percent for (pos_commission_shares). Merchant is never a typed line — it
 * is the residual (100 - Σ shares) and only appears as a party on the
 * per-sale ledger (pos_sale_commissions).
 */
enum CommissionPartyType: string
{
    case Platform = 'platform';
    case Bank = 'bank';
    case Merchant = 'merchant';
    case Other = 'other';

    /**
     * The party types an admin may configure as a share line (everyone
     * except the merchant, who takes the residual).
     *
     * @return list<string>
     */
    public static function shareValues(): array
    {
        return [
            self::Platform->value,
            self::Bank->value,
            self::Other->value,
        ];
    }
}
