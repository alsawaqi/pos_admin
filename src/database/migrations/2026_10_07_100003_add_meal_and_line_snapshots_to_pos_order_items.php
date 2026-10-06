<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH combo add-on (tester call 3) — how a combo or meal sale is stored.
 *
 * One PARENT line carries the money (unit price, line total, discounts) and
 * one CHILD line per item (parent_order_item_id, as since LAUNCH-P4):
 *
 *   meal_id                   on a meal's PARENT: the meal setup (a snapshot,
 *                             no FK; the parent has no product_id, the main
 *                             is a child of kind 'main')
 *   combo_line_id             on a CHILD: the combo / meal line it came from
 *                             (a snapshot, no FK; lines can be edited later)
 *   combo_child_kind          on a CHILD: 'fixed' | 'upgrade' | 'choice' |
 *                             'main' (CHECK)
 *   allocated_revenue_baisas  on a CHILD: its share of the parent's line
 *                             total, split in proportion to the items'
 *                             normal prices; the children of one parent add
 *                             up exactly to it (>= 0, CHECK)
 *
 * combo_extra_price (LAUNCH-P4) keeps the choice extra or the upgrade price
 * per ONE item; combo_slot_id stays for lines written before this release.
 * A child's cost basis is its own frozen recipe / component / add-on copies
 * (as today). Every existing line keeps NULL: no value changes.
 * pos:check-tenant-integrity checks that a meal_id belongs to the order's
 * merchant. pos_api and pos_merchant mirror the columns in their test schemas.
 * down() refuses once a line uses the new columns.
 */
return new class extends Migration
{
    private const KIND_CHECK = 'pos_order_items_combo_child_kind_check';

    private const ALLOCATED_CHECK = 'pos_order_items_allocated_revenue_check';

    private const COLUMNS = ['meal_id', 'combo_line_id', 'combo_child_kind', 'allocated_revenue_baisas'];

    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('meal_id')->nullable();
            $table->unsignedBigInteger('combo_line_id')->nullable();
            $table->string('combo_child_kind', 16)->nullable();
            $table->bigInteger('allocated_revenue_baisas')->nullable();
            $table->index(['meal_id'], 'pos_order_items_meal_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_order_items" ADD CONSTRAINT "'.self::KIND_CHECK.'" CHECK ("combo_child_kind" IS NULL OR "combo_child_kind" IN (\'fixed\', \'upgrade\', \'choice\', \'main\'))');
            DB::statement('ALTER TABLE "pos_order_items" ADD CONSTRAINT "'.self::ALLOCATED_CHECK.'" CHECK ("allocated_revenue_baisas" IS NULL OR "allocated_revenue_baisas" >= 0)');
        }
    }

    public function down(): void
    {
        $used = DB::table('pos_order_items')->where(static function ($query): void {
            foreach (self::COLUMNS as $column) {
                $query->orWhereNotNull($column);
            }
        })->exists();
        if ($used) {
            throw new RuntimeException('Cannot roll back 2026_10_07_100003: order lines already record meals, combo lines or allocated revenue.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_order_items" DROP CONSTRAINT IF EXISTS "'.self::KIND_CHECK.'"');
            DB::statement('ALTER TABLE "pos_order_items" DROP CONSTRAINT IF EXISTS "'.self::ALLOCATED_CHECK.'"');
        }
        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->dropIndex('pos_order_items_meal_idx');
            $table->dropColumn(self::COLUMNS);
        });
    }
};
