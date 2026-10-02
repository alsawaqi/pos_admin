<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P3 P3-4 — prep items (owner decision 2026-10-02: auto-deduct).
 *
 * A prep item (a sauce, a dough) is a pos_ingredients row with its own
 * recipe (pos_ingredient_recipes) and a yield. Dishes, cooked products and
 * add-on options use it like an ingredient; the device API explodes it into
 * its raw ingredients when an order line's recipe is copied, so a prep item
 * never holds stock of its own.
 *
 *   is_prep              true = a prep item. Existing rows stay false.
 *   prep_yield_quantity  what ONE batch of the prep recipe makes, in the prep
 *                        item's own base unit (2000 for "makes 2 L" of an
 *                        ml-based sauce). Required and > 0 when is_prep.
 *
 * On Postgres a CHECK constraint backs the "required and > 0" rule (the
 * portal enforces it too); SQLite (the test mirror) has no ALTER ... ADD
 * CONSTRAINT, so the rule is application-only there.
 *
 * Adds nullable / defaulted columns only: no existing value changes.
 * pos_merchant and pos_api mirror the columns in their test schemas.
 */
return new class extends Migration
{
    private const CHECK = 'pos_ingredients_prep_yield_check';

    public function up(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->boolean('is_prep')->default(false);
            $table->decimal('prep_yield_quantity', 14, 4)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_ingredients" ADD CONSTRAINT "'.self::CHECK.'" CHECK (NOT "is_prep" OR ("prep_yield_quantity" IS NOT NULL AND "prep_yield_quantity" > 0))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_ingredients" DROP CONSTRAINT IF EXISTS "'.self::CHECK.'"');
        }

        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->dropColumn(['is_prep', 'prep_yield_quantity']);
        });
    }
};
