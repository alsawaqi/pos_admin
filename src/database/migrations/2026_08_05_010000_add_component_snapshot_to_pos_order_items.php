<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-G2 hardening — freeze the parent line's physical-item components
 * (pos_product_components: coffee = 1 × cup + 1 × lid) onto the order
 * line at write time, the recipe_snapshot_json philosophy: pay/void
 * consume what the order knew, immune to later catalogue edits.
 *
 * Until now the parent line's components were the ONE live read in the
 * consumption path (the add-on-linked product's components were already
 * frozen inside product_snapshot_json) — so editing a product's
 * component set between a sale and its void restocked the NEW set, not
 * what was actually consumed, silently drifting pos_branch_product
 * stock and the product ledger.
 *
 * Shape: list of {product_id, qty} per ONE parent unit — identical to
 * the `components` slice inside product_snapshot_json.
 *
 * NULL is load-bearing: it means "written before this column existed" —
 * pos_api falls back to the live component read for those legacy rows
 * so in-flight orders keep consuming/reversing. An empty [] means the
 * product genuinely had no components at write time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->json('component_snapshot_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->dropColumn('component_snapshot_json');
        });
    }
};
