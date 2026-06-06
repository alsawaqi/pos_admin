<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — explicit product STOCK MODE (blueprint §5.5.3 "recipe optionality").
 *
 * A product is one of:
 *   - 'ingredient' : made to order from a recipe; availability DERIVES from
 *                    per-branch ingredient stock (deducted at sale). No unit count.
 *   - 'unit'       : a finished / solid good tracked by piece COUNT per branch
 *                    (pos_branch_product.stock_qty), fed from a central pool.
 *   - 'untracked'  : sold freely, no stock tracking (e.g. a resold bottled drink).
 *
 * Until now the mode was only IMPLIED (recipe rows present vs stock_qty present),
 * and a product could ambiguously be BOTH. Making it explicit lets the merchant
 * form show the right controls and lets the POS know whether / how to enforce
 * sold-out per product.
 *
 * Backfill keeps existing data coherent: has a recipe -> ingredient; else has any
 * per-branch unit stock -> unit; else untracked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->string('stock_mode', 16)->default('untracked')->after('delivery_price');
        });

        // Recipe-driven products → ingredient.
        DB::table('pos_products')
            ->whereIn('id', function ($q): void {
                $q->select('product_id')->distinct()->from('pos_product_recipes');
            })
            ->update(['stock_mode' => 'ingredient']);

        // Remaining products that carry per-branch unit stock → unit.
        DB::table('pos_products')
            ->where('stock_mode', 'untracked')
            ->whereIn('id', function ($q): void {
                $q->select('product_id')->distinct()->from('pos_branch_product')->whereNotNull('stock_qty');
            })
            ->update(['stock_mode' => 'unit']);
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('stock_mode');
        });
    }
};
