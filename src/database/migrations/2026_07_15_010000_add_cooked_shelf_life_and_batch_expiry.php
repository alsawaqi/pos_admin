<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-G1.5 — shelf life + per-batch expiry for cooked products.
 *
 * pos_products.shelf_life_days: the product's DEFAULT shelf life. NULL =
 * keeps indefinitely (the day-end disposition flow skips it entirely);
 * 1 = same-day; N = N days. Restaurants differ, so every variant is a
 * per-product choice. Meaningful for cooked (and other shelf-stocked)
 * products; ignored for ingredient/untracked modes.
 *
 * pos_productions.expires_at: the CHEF's per-batch truth, set on the
 * device Finish dialog (prefilled from finished_at + shelf_life_days,
 * one tap accepts the default, quick chips / date picker override it).
 * NULL = the batch never expires. The day-end disposition computes
 * "what expires today" from FIFO virtual lots: positive product-ledger
 * inflows are lots (a 'produced' lot uses ITS batch expires_at; other
 * inflows fall back to occurred_at + shelf_life_days), negative
 * movements deplete oldest-first — no lot tables needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->unsignedSmallInteger('shelf_life_days')
                ->nullable()
                ->after('low_stock_threshold');
        });

        Schema::table('pos_productions', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('pos_productions', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });

        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('shelf_life_days');
        });
    }
};
