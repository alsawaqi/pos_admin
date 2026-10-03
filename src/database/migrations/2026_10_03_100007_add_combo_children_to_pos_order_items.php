<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P4 data contract — the items chosen inside a combo line.
 *
 * A combo sale is one parent line (the combo product, carrying all the
 * revenue: the combo price, every extra price and every component add-on
 * price) and one CHILD line per chosen item:
 *
 *   parent_order_item_id  the combo line; children go with it
 *   combo_slot_id         the slot the item was chosen in — a snapshot, no FK
 *                         (slots can be edited or deleted later)
 *   combo_extra_price     the option's extra price, per one item, as charged
 *
 * A child carries the chosen product_id, qty = parent qty × component qty,
 * unit_price_snapshot = 0 and line_total = 0, its own recipe and component
 * snapshots and its own add-on rows (price_delta_snapshot kept for display;
 * the revenue sits on the parent). EVERY revenue query must exclude children
 * (parent_order_item_id IS NOT NULL); stock, kitchen and "items sold inside
 * combos" read them.
 *
 * Every existing line stays a top-level line (NULL / 0): no value changes.
 * pos_api and pos_merchant mirror the columns in their test schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->foreignId('parent_order_item_id')->nullable()
                ->constrained('pos_order_items')->cascadeOnDelete();
            $table->unsignedBigInteger('combo_slot_id')->nullable();
            $table->decimal('combo_extra_price', 12, 3)->default(0);
            $table->index(['parent_order_item_id'], 'pos_order_items_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->dropForeign(['parent_order_item_id']);
            $table->dropIndex('pos_order_items_parent_idx');
            $table->dropColumn(['parent_order_item_id', 'combo_slot_id', 'combo_extra_price']);
        });
    }
};
