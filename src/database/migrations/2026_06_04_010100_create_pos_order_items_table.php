<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7a — Order line items (blueprint §10.8).
 *
 * One row per (order, product, distinct configuration). Two
 * lattes ordered together with the same add-ons collapse to
 * ONE row with qty=2; two lattes with different add-ons stay
 * as TWO rows so each can carry its own per-line discount,
 * recipe snapshot, and kitchen status.
 *
 * SNAPSHOT COLUMNS (frozen at order-write time):
 *
 *   product_name_snapshot:
 *     The product's display name at write time. Reports
 *     (§5.11.2 Product Performance) and customer receipts
 *     show this — even if the merchant later renames the
 *     product or soft-deletes it, the historical line still
 *     renders meaningfully.
 *
 *   unit_price_snapshot:
 *     The price the customer was actually charged per unit.
 *     Could be base_price, delivery_price (Phase 4.9),
 *     a provider-override price (Phase 6c), or a price from
 *     a discount/loyalty redemption — the writing layer
 *     resolves the correct number and freezes it here.
 *
 *   recipe_snapshot_json:
 *     The product's recipe at order-write time. Phase 8 sale-
 *     consumption pipeline reads this to deduct stock per
 *     §5.6.3 stock_movements (so a later recipe edit doesn't
 *     retroactively change how much milk was consumed for an
 *     order from last week). NULL when the product had no
 *     recipe (no inventory deduction — pre-made goods).
 *
 * line_discount: signed-positive discount applied to THIS
 * line (after the discount evaluator from §5.9 fires).
 * Stored separately from the order-level discount_total so
 * reports can break down which products were discounted
 * (§5.11.7 Discount Report drill-down).
 *
 * line_total: (qty × unit_price_snapshot) - line_discount.
 * Frozen to avoid float drift in aggregation queries.
 *
 * status: open / sent_to_kitchen / ready / served / void.
 * Granular per-line so a multi-item order can have one item
 * in the kitchen and another already served. Phase 9 kitchen
 * display reads this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')
                ->constrained('pos_orders')
                ->cascadeOnDelete();
            // Product is nullable on hard-delete so the
            // historical line survives even if the merchant
                // physically removes the product (soft delete
                // doesn't trip this; the FK only fires on
                // hard delete).
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('pos_products')
                ->nullOnDelete();

            // ---- Snapshots ----
            $table->string('product_name_snapshot');
            // qty stored as decimal(12,3) — allows fractional
            // sales (e.g. 0.500 kg of meat for a butcher) even
            // though most coffeeshop pilots will only see
            // whole numbers.
            $table->decimal('qty', 12, 3)->default('1.000');
            $table->decimal('unit_price_snapshot', 12, 3);
            $table->decimal('line_discount', 12, 3)->default(0);
            $table->decimal('line_total', 12, 3);

            // JSONB on Postgres (Laravel's ->json() maps to
            // jsonb in pg). Mirrored as TEXT in the merchant
            // test-schema migration since sqlite has no jsonb.
            // Phase 8 reads this to drive stock deduction.
            $table->json('recipe_snapshot_json')->nullable();

            // ---- Per-line lifecycle ----
            // open / sent_to_kitchen / ready / served / void.
            // Plain string; app enum
            // {@see App\Enums\OrderItemStatus} restricts values
            // (Phase 8 fully adopts).
            $table->string('status', 32)->default('open');

            $table->text('notes')->nullable();

            $table->timestamps();

            // Hot path: every Order.items() eager-load.
            $table->index(['order_id'], 'pos_order_items_order_idx');
            // Product performance report (§5.11.2) aggregates
            // by product across an opened_at window — index
            // helps the JOIN.
            $table->index(['product_id'], 'pos_order_items_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_items');
    }
};
