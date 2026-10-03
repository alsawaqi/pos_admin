<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P4 data contract — combos, channels and branch scope on products.
 *
 *   product_type      'standard' | 'combo'. A combo has stock_mode
 *                     'untracked', no recipe and no components (the portal
 *                     enforces it); its choices live in pos_combo_slots.
 *   sold_in_store     false = not offered for in-store order types (quick,
 *                     dine_in, to_go, car) — the till, the handheld and QR.
 *   sold_on_delivery  false = not offered on delivery orders.
 *   branch_scope      'all'      = every branch sells it, except a branch
 *                                  whose pos_branch_product row says
 *                                  is_available = false;
 *                     'selected' = only branches whose row says
 *                                  is_available = true.
 *                     Stock rows never restrict on their own (H6).
 *   description_ar    the Arabic description (L5).
 *
 * Every existing product keeps today's behaviour: a product that has any
 * pos_branch_product row today is shown only where a row is available, so it
 * becomes 'selected'; a product with no row stays 'all'. The backfill writes
 * the new column only (no updated_at bump, no existing value changes).
 *
 * On Postgres CHECK constraints back the two enumerations; SQLite (the test
 * mirror) has no ALTER ... ADD CONSTRAINT, so they are application-only there.
 * pos_api and pos_merchant mirror the columns in their test schemas.
 */
return new class extends Migration
{
    private const TYPE_CHECK = 'pos_products_product_type_check';

    private const SCOPE_CHECK = 'pos_products_branch_scope_check';

    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->string('product_type', 16)->default('standard');
            $table->boolean('sold_in_store')->default(true);
            $table->boolean('sold_on_delivery')->default(true);
            $table->string('branch_scope', 16)->default('all');
            $table->text('description_ar')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_products" ADD CONSTRAINT "'.self::TYPE_CHECK.'" CHECK ("product_type" IN (\'standard\', \'combo\'))');
            DB::statement('ALTER TABLE "pos_products" ADD CONSTRAINT "'.self::SCOPE_CHECK.'" CHECK ("branch_scope" IN (\'all\', \'selected\'))');
        }

        DB::table('pos_products')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('pos_branch_product')
                    ->whereColumn('pos_branch_product.product_id', 'pos_products.id');
            })
            ->update(['branch_scope' => 'selected']);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_products" DROP CONSTRAINT IF EXISTS "'.self::TYPE_CHECK.'"');
            DB::statement('ALTER TABLE "pos_products" DROP CONSTRAINT IF EXISTS "'.self::SCOPE_CHECK.'"');
        }

        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn(['product_type', 'sold_in_store', 'sold_on_delivery', 'branch_scope', 'description_ar']);
        });
    }
};
