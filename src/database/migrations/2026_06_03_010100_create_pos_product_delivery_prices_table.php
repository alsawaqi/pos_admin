<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6c — per-provider product price overrides (blueprint §6.3).
 *
 * N:M-ish pivot between products and delivery providers, with
 * one extra value (price). One row per (product, provider) —
 * the unique constraint enforces that.
 *
 * Price-resolution chain at POS time (Phase 8+):
 *   1. Look up pos_product_delivery_prices for the
 *      (product, provider) pair. If found, use it.
 *   2. Else fall back to pos_products.delivery_price (Phase
 *      4.9). Used for in-house delivery or when the merchant
 *      hasn't configured this provider yet.
 *   3. Else fall back to pos_products.base_price.
 *
 * Merchant only configures overrides where they actually need
 * to deviate. The 3-step fallback keeps the configuration
 * surface manageable for big catalogues × many providers.
 *
 * company_id denormalised from the product. Two upsides:
 *   - Tenant-scoped reports ("all overrides for company X")
 *     skip the join through products.
 *   - Phase 8 POS sale pipeline + the Action layer can
 *     cross-check that product.company_id == provider
 *     .company_id == this row's company_id BEFORE writing
 *     a sale, surfacing a misconfiguration loudly.
 *
 * Cascade-on-product-delete: when a product is soft-deleted
 * the override rows survive (FK doesn't fire on soft delete).
 * When the product is hard-deleted the rows cascade away.
 *
 * Cascade-on-provider-delete: same behaviour — soft delete
 * leaves rows in place for historical orders.
 *
 * No soft delete on the override row itself: removing a price
 * override is a clean operation (just deletes the row, falls
 * back to the next step in the chain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_product_delivery_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('pos_products')
                ->cascadeOnDelete();
            $table->foreignId('delivery_provider_id')
                ->constrained('pos_delivery_providers')
                ->cascadeOnDelete();
            // Denormalised from product for tenant-scoped reads.
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->decimal('price', 12, 3);
            $table->timestamps();
            $table->unique(
                ['product_id', 'delivery_provider_id'],
                'pos_product_delivery_prices_product_provider_unique',
            );
            // Hot paths: "all overrides for product X" (used by
            // the merchant's product modal) + "all overrides
            // for provider Y" (used when the merchant clones a
            // price list across SKUs).
            $table->index(['product_id'], 'pos_product_delivery_prices_product_idx');
            $table->index(['delivery_provider_id'], 'pos_product_delivery_prices_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_product_delivery_prices');
    }
};
