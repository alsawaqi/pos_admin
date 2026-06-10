<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D2 — catalogue flags (blueprint §5.5.1 / §5.5.3 / §10.4).
 *
 * pos_products gains three additive columns:
 *
 *   low_stock_threshold — decimal(12,3) nullable. §5.5.3 "Low stock
 *     label": a unit-mode product shows a LOW STOCK badge on the POS
 *     card when its branch unit stock falls to/below this. NULL = no
 *     badge. (Ingredient-mode products derive their badge from each
 *     recipe ingredient's min_stock_threshold instead — that signal is
 *     already emitted in /device/config.)
 *
 *   tax_inclusive — boolean default false. §5.5.3 "Tax-inclusive or
 *     tax-exclusive pricing flag" — per product in the spec. STORED +
 *     DISPLAYED ONLY for now: order totals still add company taxes on
 *     top (exclusive), because honouring the flag needs a per-line tax
 *     engine on the device plus a server-side parity recompute, and
 *     back-computing base = price/(1+r) per line risks rounding drift
 *     against the sync money invariant (subtotal − discount − comp +
 *     tax == grand ±1 baisa). This column gates that later phase
 *     without schema churn.
 *
 *   show_on_customer_tablet — boolean default true. §5.5.3 "Show on
 *     Customer Tablet menu yes/no". Carried in /device/config for the
 *     future customer tablet menu app; the main POS ignores it.
 *
 * pos_product_categories gains branch_availability_json (§5.5.1
 * "branch availability (all or selected)" — §10.4's exact column):
 * NULL = all branches; else a JSON array of pos_branches ids. A JSON
 * column — deliberately NOT a pivot — so an availability flip bumps
 * the row's own updated_at and reaches delta-syncing devices (the
 * pos_branch_product pivot's delta blind spot is not repeated here).
 * The DEVICE hides non-matching categories from its strip; the server
 * keeps emitting them all, because a category newly excluded from a
 * branch is not soft-deleted and would never surface in the delta
 * `deleted` map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->decimal('low_stock_threshold', 12, 3)->nullable()->after('stock_mode');
            $table->boolean('tax_inclusive')->default(false)->after('tax_rate');
            $table->boolean('show_on_customer_tablet')->default(true)->after('status');
        });

        Schema::table('pos_product_categories', function (Blueprint $table): void {
            $table->json('branch_availability_json')->nullable()->after('display_order');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn(['low_stock_threshold', 'tax_inclusive', 'show_on_customer_tablet']);
        });

        Schema::table('pos_product_categories', function (Blueprint $table): void {
            $table->dropColumn('branch_availability_json');
        });
    }
};
