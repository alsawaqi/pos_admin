<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH review add-on §3.1 / 02 — two new columns on pos_ingredients.
 *
 *   count_container_id  the container tills and handhelds count this item
 *                       in (tester call 6). The four piece_* /
 *                       units_per_piece / allow_fractional_pieces columns
 *                       stay as its MIRROR for pos_api and installed apps.
 *                       Deleting the container empties the link.
 *   sku                 the item's SKU (supplier code or generated
 *                       ING-0001). One per company, case-insensitive,
 *                       among live rows: partial unique
 *                       (company_id, lower(sku)) WHERE sku IS NOT NULL AND
 *                       deleted_at IS NULL. The cross-table rule with
 *                       pos_products.sku is an app check (tester call 11).
 *
 * Both are NULL for every existing row; 100003 and 100004 fill them.
 */
return new class extends Migration
{
    private const SKU = 'pos_ingredients_company_sku_unique';

    public function up(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->foreignId('count_container_id')->nullable()->constrained('pos_ingredient_units')->nullOnDelete();
            $table->string('sku', 64)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX "'.self::SKU.'" ON "pos_ingredients" ("company_id", lower("sku")) WHERE "sku" IS NOT NULL AND "deleted_at" IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS "'.self::SKU.'"');

        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->dropForeign(['count_container_id']);
            $table->dropColumn(['count_container_id', 'sku']);
        });
    }
};
