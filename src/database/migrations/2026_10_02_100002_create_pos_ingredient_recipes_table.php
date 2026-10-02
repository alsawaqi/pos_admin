<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P3 P3-4 — a prep item's recipe: what ONE batch is made of.
 *
 *   prep_ingredient_id  the prep item (pos_ingredients.is_prep); its recipe
 *                       goes with it if the row is ever hard-deleted
 *   ingredient_id       a component: a raw ingredient or another prep item
 *                       (restrict: a component cannot be hard-deleted while
 *                       a recipe names it)
 *   quantity            per ONE batch, in the COMPONENT's base unit
 *   entered_unit /      how the line was typed in the editor (P3-1), so it
 *   entered_quantity    reopens exactly as entered; quantity stays the base
 *
 * Explode rule (shared with pos_api): a line using prep item P with quantity
 * q becomes, for each component c, q × c.quantity ÷ P.prep_yield_quantity of
 * c, repeated until only raw ingredients remain. Components may be prep items
 * up to 3 levels deep, cycles are refused and both rows belong to the same
 * company — the portal enforces all three when a prep recipe is saved.
 *
 * On Postgres two CHECK constraints back the cheap invariants (a positive
 * quantity; a prep item never lists itself). SQLite has no ALTER ... ADD
 * CONSTRAINT, so they are application-only there.
 *
 * pos_merchant and pos_api mirror the table in their test schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_ingredient_recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prep_ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('pos_ingredients')
                ->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->string('entered_unit', 32)->nullable();
            $table->decimal('entered_quantity', 14, 4)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['prep_ingredient_id', 'ingredient_id'], 'pos_ingredient_recipes_prep_ingredient_unique');
            // "Where is this ingredient used?" (delete / unit-change guards).
            $table->index(['ingredient_id'], 'pos_ingredient_recipes_ingredient_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_ingredient_recipes" ADD CONSTRAINT "pos_ingredient_recipes_quantity_check" CHECK ("quantity" > 0)');
            DB::statement('ALTER TABLE "pos_ingredient_recipes" ADD CONSTRAINT "pos_ingredient_recipes_not_self_check" CHECK ("prep_ingredient_id" <> "ingredient_id")');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_ingredient_recipes');
    }
};
