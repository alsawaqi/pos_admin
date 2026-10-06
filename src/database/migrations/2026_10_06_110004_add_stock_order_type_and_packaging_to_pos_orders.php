<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH packaging add-on §2 tester call 1 — the order remembers how its
 * stock was taken, so a void restores exactly that.
 *
 *   stock_order_type         the order-type bucket the stock lines were
 *                            filtered by: 'dine_in' | 'quick' | 'to_go' |
 *                            'delivery' (`car` → 'to_go'); the order's FINAL
 *                            type at payment, or 'delivery' at hand-off.
 *                            NULL = stock not taken yet, or taken before this
 *                            release (then nothing is filtered on void).
 *                            Postgres CHECK: NULL or one of the four.
 *   packaging_snapshot_json  the per-order packaging taken with it, frozen:
 *                            {"order_type": "to_go", "lines": [
 *                              {"type": "ingredient", "ingredient_id": 7, "qty": 1, "unit": "pcs", "unit_cost": 0.02},
 *                              {"type": "product", "product_id": 9, "qty": 2}]}
 *                            NULL = no packaging taken (nothing to restore).
 *
 * Both are stamped once, in the transaction that takes the stock, and never
 * rewritten. Nullable, no default: existing orders are unchanged and the
 * columns are added without a table rewrite.
 */
return new class extends Migration
{
    private const CHECK = 'pos_orders_stock_order_type_check';

    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->string('stock_order_type', 16)->nullable();
            $table->json('packaging_snapshot_json')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_orders" ADD CONSTRAINT "'.self::CHECK.'" CHECK ("stock_order_type" IS NULL OR "stock_order_type" IN (\'dine_in\', \'quick\', \'to_go\', \'delivery\'))');
        }
    }

    public function down(): void
    {
        // Fix order PK-A1 (M2) — once an order's stock was taken by order
        // type, its void must read the stamp and the frozen packaging
        // (otherwise it restores lines that were never taken and never gives
        // the packaging back): refuse while any order carries either.
        if (DB::table('pos_orders')->whereNotNull('stock_order_type')->orWhereNotNull('packaging_snapshot_json')->exists()) {
            throw new RuntimeException('Cannot roll back 2026_10_06_110004: orders already carry a stock order type or frozen packaging; their voids need them.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_orders" DROP CONSTRAINT IF EXISTS "'.self::CHECK.'"');
        }

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn(['stock_order_type', 'packaging_snapshot_json']);
        });
    }
};
