<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5b — Product recipes (blueprint §5.5.3 + §10.4).
 *
 * One row per (product, ingredient) — the line items of the
 * product's recipe. A product with zero rows here is "pre-
 * made / no inventory deduction" (e.g. a bottled soft drink
 * resold as-is). A product with rows is recipe-driven and
 * triggers inventory consumption when sold (Phase 8).
 *
 * Columns:
 *   quantity         — decimal(12,3), same precision as
 *                       branch_stock + stock_movements so the
 *                       deduction math stays exact through the
 *                       sale path.
 *   unit_at_set      — denormalised from the ingredient at the
 *                       time the line was written. If the
 *                       merchant later edits the ingredient's
 *                       unit (which Phase 5a only allows when
 *                       no history exists — but for safety) the
 *                       recipe line still reflects what was
 *                       intended at this snapshot.
 *   sort_order       — display order in the recipe editor.
 *
 * Composite unique (product_id, ingredient_id) — an
 * ingredient appears at most once in a recipe (you'd just
 * sum the quantities if you genuinely wanted "1g sugar twice").
 *
 * Cascade-on-product-delete: removing a product wipes its
 * recipe. No soft delete here — the recipe versions table
 * preserves history for COGS reporting.
 *
 * Phase 8 sale-consumption path: when an order line lands,
 * the action reads these rows for the product, snapshots
 * them onto the order_line_addons recipe_snapshot column,
 * then calls WriteStockMovementAction per ingredient with
 * type=sale_consumption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_product_recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('pos_products')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            // Denormalised — see class doc.
            $table->string('unit_at_set', 16);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'ingredient_id'], 'pos_product_recipes_product_ingredient_unique');
            $table->index(['ingredient_id'], 'pos_product_recipes_ingredient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_product_recipes');
    }
};
