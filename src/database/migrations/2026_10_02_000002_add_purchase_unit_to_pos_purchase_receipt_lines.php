<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P2 P2-3 — the unit picker on goods received.
 *
 * A receipt line may now be entered in a purchase unit (25 kg, 3 boxes,
 * 12 bottles) with a price per that unit. `quantity` keeps meaning the total
 * in the item's BASE unit and `line_cost` the net line total, exactly as
 * before; these nullable columns only record how the line was entered, so the
 * saved document reads back the way it was typed:
 *
 *   purchase_unit      the unit chosen ('kg', an extra unit's name, '@piece'
 *                      for the ingredient's piece unit); NULL = base unit
 *   purchase_quantity  the quantity in that unit
 *   unit_price         price per purchase unit as entered (net of tax)
 *   unit_cost          the price per BASE unit the stock movement was stamped
 *                      with (6 decimals) — the weighted-average input
 *
 * Every existing line keeps NULLs (entered in the base unit). pos_merchant
 * mirrors the columns in its test schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_purchase_receipt_lines', function (Blueprint $table): void {
            $table->string('purchase_unit', 40)->nullable();
            $table->decimal('purchase_quantity', 14, 4)->nullable();
            $table->decimal('unit_price', 15, 6)->nullable();
            $table->decimal('unit_cost', 15, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_purchase_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn(['purchase_unit', 'purchase_quantity', 'unit_price', 'unit_cost']);
        });
    }
};
