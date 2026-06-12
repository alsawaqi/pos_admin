<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-G3 — product-as-add-on (cake inside a coffee).
 *
 * pos_addons.linked_product_id: when set, the add-on IS that product —
 * offered at the add-on's own price_delta (same or different from the
 * standalone price, per group). Selling it consumes the product's REAL
 * stock by its type: cooked/ready -> branch shelf -1 per unit,
 * made-to-order -> its recipe, untracked -> nothing. The same shelf pool
 * serves standalone sales, add-on sales and offers — when the kitchen
 * hasn't produced cake today, the tile AND the add-on grey out together.
 * Mutually exclusive with the classic single-ingredient link
 * (ingredient_id) — app-enforced.
 *
 * pos_order_item_addons gains the freeze-at-create pair (the
 * recipe_snapshot_json philosophy: pay/void consume what the order knew,
 * immune to later catalogue edits):
 *
 *   linked_product_id      plain indexed column for SQL reporting —
 *                          add-on sales count into the product's
 *                          performance numbers;
 *   product_snapshot_json  { product_id, stock_mode, recipe: [...] }
 *                          driving consumption at pay (sign-symmetric
 *                          on void). Quantity per add-on selection is
 *                          always 1 x the parent line qty (agreed
 *                          default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_addons', function (Blueprint $table): void {
            $table->foreignId('linked_product_id')
                ->nullable()
                ->after('ingredient_unit')
                ->constrained('pos_products')
                ->nullOnDelete();
        });

        Schema::table('pos_order_item_addons', function (Blueprint $table): void {
            $table->foreignId('linked_product_id')
                ->nullable()
                ->after('ingredient_snapshot_json')
                ->constrained('pos_products')
                ->nullOnDelete();
            $table->json('product_snapshot_json')->nullable()->after('linked_product_id');

            $table->index(['linked_product_id'], 'pos_order_item_addons_linked_product_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_item_addons', function (Blueprint $table): void {
            $table->dropIndex('pos_order_item_addons_linked_product_idx');
            $table->dropConstrainedForeignId('linked_product_id');
            $table->dropColumn('product_snapshot_json');
        });

        Schema::table('pos_addons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('linked_product_id');
        });
    }
};
