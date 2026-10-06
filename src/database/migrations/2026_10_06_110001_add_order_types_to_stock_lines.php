<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH packaging add-on §3 Part A / 1 — "Used for" ticks (owner decision 1).
 *
 *   order_types  smallint NOT NULL DEFAULT 15, a bit mask of the order
 *                types a stock line is taken for:
 *                  1 dine in (device dine-in, QR table, staff rounds)
 *                  2 quick order (device counter, QR quick)
 *                  4 to go (and `car`)
 *                  8 delivery
 *                15 = all four, today's behaviour. Postgres CHECK 1..15
 *                (a line used for nothing is deleted, not stored as 0).
 *
 * On every stock line table:
 *   pos_product_recipes      recipe ingredient lines (honoured on
 *                            made-to-order products only; cooked products
 *                            use their recipe at production)
 *   pos_product_components   physical-item lines (every stock mode)
 *   pos_addon_consumptions   add-on stock lines
 *   pos_addons               the legacy single-ingredient fields
 *                            (ingredient_id / ingredient_qty /
 *                            ingredient_unit) only; a linked product's own
 *                            shelf piece is the item sold and always moves
 *
 * Every existing row gets 15 (constant default: metadata only in Postgres,
 * no table rewrite), and pos_api leaves the mask out of a copy when it is
 * 15, so nothing a merchant has not unticked changes.
 */
return new class extends Migration
{
    public const TABLES = ['pos_product_recipes', 'pos_product_components', 'pos_addon_consumptions', 'pos_addons'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedSmallInteger('order_types')->default(15);
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE "'.$name.'" ADD CONSTRAINT "'.$name.'_order_types_check" CHECK ("order_types" BETWEEN 1 AND 15)');
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE "'.$name.'" DROP CONSTRAINT IF EXISTS "'.$name.'_order_types_check"');
            }

            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('order_types');
            });
        }
    }
};
