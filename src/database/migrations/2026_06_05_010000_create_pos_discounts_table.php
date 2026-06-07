<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6d — Discount rules (blueprint §5.9 + §10.7).
 *
 * Per-merchant rules that apply discounts at POS time. The
 * applicability test is a 6-axis predicate:
 *
 *   1. status == active
 *   2. now() in [validity_start, validity_end]
 *   3. day-of-week bitmask matches today
 *   4. now() time-of-day in [time_start, time_end]
 *   5. branch in branch_scope_json (null = all)
 *   6. order matches scope:
 *        - product → at least one line.product_id in
 *          pos_discount_targets where target_type=product
 *        - category → at least one line's product's
 *          category_id in pos_discount_targets where
 *          target_type=category
 *        - order → unconditional, applies to the order total
 *
 * The Phase 6d-4 evaluateDiscounts() pure function takes a
 * pre-filtered list of applicable rules (the caller checks
 * 1-5 against the order context) and returns lineDiscounts +
 * orderDiscount with the actual money math.
 *
 * Stackable rules combine multiplicatively (% off applied
 * after another % off compounds the savings); non-stackable
 * rules take precedence in stackable=true ones (the merchant's
 * intent is "this rule is exclusive").
 *
 * dayofweek_mask: bitmask
 *   Sun=1, Mon=2, Tue=4, Wed=8, Thu=16, Fri=32, Sat=64.
 *   0b1111111 (= 127) = "every day". NULL semantics treated
 *   as 127 by the evaluator (rule applies every day).
 *
 * time_start + time_end: HH:MM:SS strings. NULL = "all day".
 * Midnight-wrap supported: time_start=22:00 + time_end=02:00
 * matches the 22:00→24:00 AND 00:00→02:00 windows on the
 * matched day.
 *
 * branch_scope_json: same pattern as pos_users.branch_scope_json
 * from Phase 4.5. NULL = all branches, [int, int, ...] = subset.
 *
 * Soft delete: orders (Phase 7a) snapshot discount applications;
 * a soft-deleted rule still resolves for historical reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_discounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();

            $table->string('name');
            // 32-char string. App enum
            // {@see App\Enums\DiscountScope}: product / category /
            // order.
            $table->string('scope', 32);
            // 32-char string. App enum
            // {@see App\Enums\DiscountAmountType}: percent / fixed.
            $table->string('amount_type', 32);
            // For percent: 0-100 (interpreted as %). For fixed:
            // OMR amount (decimal:3 baisas precision). The form
            // request validates the % cap.
            $table->decimal('amount', 12, 3);

            // Validity window. NULL = "no bound" on that end.
            $table->timestamp('validity_start')->nullable();
            $table->timestamp('validity_end')->nullable();

            // Day-of-week bitmask (Sun=1..Sat=64). NULL = every day.
            $table->unsignedTinyInteger('dayofweek_mask')->nullable();

            // Time-of-day window. NULL = all day. HH:MM:SS
            // strings; midnight-wrap supported by the
            // evaluator's predicate.
            $table->string('time_start', 8)->nullable();
            $table->string('time_end', 8)->nullable();

            // NULL = all branches; array of int branch_ids = subset.
            // Same shape as portal_users.branch_scope_json
            // (Phase 4.5).
            $table->json('branch_scope_json')->nullable();

            $table->boolean('stackable')->default(false);
            $table->boolean('requires_manager_approval')->default(false);

            // 32-char string. App enum
            // {@see App\Enums\DiscountStatus}: active / paused /
            // expired.
            $table->string('status', 32)->default('active');

            $table->timestamps();
            $table->softDeletes();

            // Hot path: "all active discounts for company X" —
            // POS picker query at order-write time.
            $table->index(['company_id', 'status'], 'pos_discounts_company_status_idx');
            // "Discounts in a validity window" — admin reports
            // + the §5.11.7 Discount Report.
            $table->index(['company_id', 'validity_start', 'validity_end'], 'pos_discounts_company_validity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_discounts');
    }
};
