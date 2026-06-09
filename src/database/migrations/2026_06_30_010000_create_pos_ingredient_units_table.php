<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 #13 — per-ingredient ALTERNATE units (blueprint §5.5.3 / §5.6.5).
 *
 * Each ingredient keeps ONE base unit (pos_ingredients.unit) in which every
 * stored quantity lives — pos_branch_stock, pos_stock_movements, recipe
 * consumption. This table adds optional alternate units a merchant can BUY /
 * enter in (e.g. base = g, alt "kg" factor 1000; base = piece, alt "box of 24"
 * factor 24). `factor` = how many BASE units equal ONE of this alt unit, so
 * entered_qty × factor → base units at the point of entry.
 *
 * Design (convert-at-entry, store-in-base): conversion happens only where a
 * human enters a quantity (restock / recipe line / adjust / transfer / waste /
 * restock request). Storage stays in base, so the device + pos_api stock math
 * and reports are unchanged. Recipe lines snapshot the chosen unit's LABEL into
 * pos_product_recipes.unit_at_set (a string), so deleting an alt unit never
 * breaks recipe history — hence soft-deletes here are belt-and-braces, not an
 * FK requirement.
 *
 * company_id is denormalised (mirrors pos_branch_stock etc.) for tenant-scoped
 * queries without a join. unique(ingredient_id, name) keeps an ingredient's
 * unit labels unambiguous; the request layer also forbids an alt unit named the
 * same as the base unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_ingredient_units', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();
            // The alt-unit label, e.g. "kg", "box of 24". Free-form (a merchant
            // names what they buy in), distinct from the base IngredientUnit enum.
            $table->string('name', 32);
            $table->string('name_ar', 32)->nullable();
            // Base units per ONE of this alt unit. > 0. decimal(14,4) so fine
            // factors (e.g. a 236.5882 ml "cup") survive, feeding a (12,3) base.
            $table->decimal('factor', 14, 4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['ingredient_id', 'name'], 'pos_ingredient_units_ingredient_name_unique');
            $table->index(['company_id', 'ingredient_id'], 'pos_ingredient_units_company_ingredient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_ingredient_units');
    }
};
