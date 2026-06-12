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
    // P-G1 kitchen production: recipe ingredients leave the branch shelf
    // when the chef STARTS a batch (negative), and come back if a manager
    // cancels the in-progress batch (positive).
    case ProductionConsumption = 'production_consumption';
    case ProductionReturn = 'production_return';
    // P-G4 central ingredient warehouse (branch_id NULL = the central pool):
    // received (+central), allocation_out (-central) paired with
    // allocation_in (+branch). Written by pos_merchant only.
    case Received = 'received';
    case AllocationOut = 'allocation_out';
    case AllocationIn = 'allocation_in';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
