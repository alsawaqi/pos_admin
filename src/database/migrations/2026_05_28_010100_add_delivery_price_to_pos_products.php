<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4.9 — per-product delivery pricing.
 *
 * Restaurants and coffee shops in Oman commonly run the same
 * menu through external delivery aggregators (Talabat, etc.)
 * at higher prices than dine-in / quick-order — to absorb the
 * aggregator commission. The merchant needs to bake those
 * deltas into MITHQAL so when a cashier picks
 * order_type=delivery, the POS resolves products at the
 * higher price.
 *
 * Design: single nullable column on pos_products.
 *   - NULL  → use base_price (no delivery markup for this item)
 *   - Non-null → use this when the order's order_type is
 *                delivery; otherwise still use base_price.
 *
 * Same decimal(12,3) shape as base_price so OMR-baisa
 * precision stays consistent across price columns.
 *
 * Future extension path: if the merchant later wants per-
 * channel pricing across more order types (different price
 * for customer-tablet self-service vs dine-in, etc.), break
 * this into a separate pos_product_prices(product_id, channel,
 * price) table. For Pilot-1 the single column is enough.
 *
 * Price resolution helper lives on the Product model:
 * Product::priceFor(string $orderType): string — returns
 * delivery_price when orderType=delivery and a delivery_price
 * is set, base_price otherwise. POS / sync endpoint reads
 * through this so the math is centralised in ONE place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            // Placed right after base_price so the column order
            // tells the story when a human reads the schema.
            $table->decimal('delivery_price', 12, 3)
                ->nullable()
                ->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('delivery_price');
        });
    }
};
