<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 5a — stock movement classification.
 * Mirror of pos_merchant's enum.
 */
enum StockMovementType: string
{
    case Initial = 'initial';
    case Restock = 'restock';
    case SaleConsumption = 'sale_consumption';
    case AddOnConsumption = 'addon_consumption';
    case Waste = 'waste';
    case Loss = 'loss';
    case Adjustment = 'adjustment';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
