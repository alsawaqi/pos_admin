<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH review add-on §3.1 / 09 — the menu (owner decisions D9–D11).
 *
 *   pos_combo_slots.is_main           the combo's main slot ("Make it a
 *                                     meal?"); at most one per combo
 *                                     (partial unique, Postgres and SQLite);
 *                                     the portal allows it only on a
 *                                     min = max = 1 slot (tester call 15)
 *   pos_products.on_sale_from /       limited-time dates (inclusive, Asia/
 *     on_sale_until                   Muscat calendar dates); NULL = no
 *                                     bound. New names: available_from /
 *                                     available_until stay the daily hours
 *   pos_products.cooking_minutes      0..240, NULL = not set
 *   pos_order_items.cooking_minutes   pos_api's snapshot when a line is
 *                                     written (a combo parent = its longest
 *                                     child); NULL for every existing line
 *
 * Postgres CHECKs: until >= from, cooking 0..240. Every existing row keeps
 * false / NULL, so nothing on sale today changes.
 */
return new class extends Migration
{
    private const MAIN = 'pos_combo_slots_one_main_unique';

    public function up(): void
    {
        Schema::table('pos_combo_slots', function (Blueprint $table): void {
            $table->boolean('is_main')->default(false);
        });
        DB::statement('CREATE UNIQUE INDEX "'.self::MAIN.'" ON "pos_combo_slots" ("combo_product_id") WHERE "is_main"');

        Schema::table('pos_products', function (Blueprint $table): void {
            $table->date('on_sale_from')->nullable();
            $table->date('on_sale_until')->nullable();
            $table->smallInteger('cooking_minutes')->nullable();
        });

        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->smallInteger('cooking_minutes')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_products" ADD CONSTRAINT "pos_products_on_sale_dates_check" CHECK ("on_sale_until" IS NULL OR "on_sale_from" IS NULL OR "on_sale_until" >= "on_sale_from")');
            DB::statement('ALTER TABLE "pos_products" ADD CONSTRAINT "pos_products_cooking_minutes_check" CHECK ("cooking_minutes" IS NULL OR ("cooking_minutes" >= 0 AND "cooking_minutes" <= 240))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_products" DROP CONSTRAINT IF EXISTS "pos_products_cooking_minutes_check"');
            DB::statement('ALTER TABLE "pos_products" DROP CONSTRAINT IF EXISTS "pos_products_on_sale_dates_check"');
        }

        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->dropColumn('cooking_minutes');
        });
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn(['on_sale_from', 'on_sale_until', 'cooking_minutes']);
        });

        DB::statement('DROP INDEX IF EXISTS "'.self::MAIN.'"');
        Schema::table('pos_combo_slots', function (Blueprint $table): void {
            $table->dropColumn('is_main');
        });
    }
};
