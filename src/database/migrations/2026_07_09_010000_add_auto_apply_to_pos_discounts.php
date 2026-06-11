<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-F4 — discounts: merchant-controlled auto-application.
 *
 * pos_discounts gains `auto_apply` (boolean NOT NULL default false).
 *
 * Business semantics:
 *
 *   - product / category scope rules ALREADY auto-apply per cart
 *     line on the device — that behavior is inherent to targeted
 *     rules and does NOT become configurable. The device ignores
 *     the flag for these scopes (they stay automatic), and the
 *     merchant UI shows it as static "applies automatically to
 *     matching items" text rather than a toggle.
 *
 *   - ORDER-scope rules are where the flag adds real control:
 *     auto_apply=true means "this discount applies by itself to
 *     every qualifying order" (qualifying = the existing 6-axis
 *     predicate: status/validity/day-of-week/time/branch/scope);
 *     auto_apply=false keeps today's behavior — the cashier picks
 *     it manually from the POS discount picker.
 *
 * Backfill: targeted (product/category) rules are set to TRUE so
 * the stored value mirrors their de-facto always-automatic
 * behavior; the merchant write path (pos_merchant Create/Update
 * DiscountAction) keeps forcing TRUE for those scopes from now on.
 * Order-scope rules stay FALSE (= manual picker, today's behavior).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_discounts', function (Blueprint $table): void {
            $table->boolean('auto_apply')->default(false);
        });

        // Preserve the existing always-auto behavior of targeted rules:
        // product/category scopes have always applied automatically per
        // matching cart line, so their stored flag starts TRUE.
        DB::table('pos_discounts')
            ->whereIn('scope', ['product', 'category'])
            ->update(['auto_apply' => true]);
    }

    public function down(): void
    {
        Schema::table('pos_discounts', function (Blueprint $table): void {
            $table->dropColumn('auto_apply');
        });
    }
};
