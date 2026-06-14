<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product wastage — extend the product-stock ledger so a WASTE movement can
 * carry a reason and a frozen unit cost, bringing cooked + bought-in product
 * waste to parity with ingredient waste (pos_waste_records).
 *
 * Two nullable columns on pos_product_stock_movements (set only on a 'waste'
 * row; NULL everywhere else and on every pre-existing row):
 *   - reason     — the WasteReason value (expired / spoiled / broken / dropped /
 *                  contamination / other), so the Loss/Waste report can break
 *                  product waste down by reason like ingredient waste.
 *   - unit_cost  — the per-unit cost FROZEN at waste time: a product's cost_price
 *                  when set, else (for a cooked item) its recipe cost. Frozen so
 *                  a later price/recipe edit doesn't retroactively shift the
 *                  recorded loss. The report prefers this over the live
 *                  cost_price, falling back to cost_price for older rows (e.g.
 *                  the F1.5 expiry-disposition rows that predate this column).
 *
 * Schema owner: pos_admin. Mirrored into the pos_merchant + pos_api test schemas.
 * Wastage is a LOSS-tracking movement, NOT a new expense (the cash model already
 * expensed the cost at purchase/production), so no expense table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_product_stock_movements', function (Blueprint $table): void {
            $table->string('reason', 32)->nullable()->after('movement_type');
            $table->decimal('unit_cost', 12, 3)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('pos_product_stock_movements', function (Blueprint $table): void {
            $table->dropColumn(['reason', 'unit_cost']);
        });
    }
};
