<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.10 — Discount-application records (blueprint §5.11.7 + §9.1.6).
 *
 * The per-order audit trail of WHICH discount rule granted HOW MUCH. The
 * pos_orders header carries only the aggregate discount_total; the §5.11.7
 * Discount Report's by-RULE breakdown (and any "which rules are actually
 * used" analysis) needs the rule-level attribution, which lands here.
 *
 * Written by the pos_api sale pipeline at order.create (the offline POS
 * already evaluated the rules — pricing is SNAPSHOT-AUTHORITATIVE per §9.1.6,
 * so the server records what the device applied rather than re-deriving it).
 *
 * Snapshot columns (name_snapshot / amount_type_snapshot) freeze the rule as
 * it stood at sale time so a later rename — or a soft-deleted rule — still
 * reads correctly in historical reports (mirrors the pos_orders doc note:
 * "orders snapshot discount applications").
 *
 *   - order_item_id NULL  → an order-level discount (applied to the whole
 *     order); set → the discount attached to one line.
 *   - discount_id   NULL  → a manual / ad-hoc discount with no catalogue rule
 *     behind it (e.g. a manager comp); set → the pos_discounts rule applied.
 *
 * amount is the OMR value (decimal:3, baisas precision) attributed to this
 * rule for this order/line — NEVER a float, NEVER a percentage (the percent
 * vs fixed nature is recorded separately in amount_type_snapshot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_order_discounts', function (Blueprint $table): void {
            $table->id();

            // ---- Tenant + scope (denormalised so the report scopes without
            // a join back through pos_orders for the company predicate) ----
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();

            // ---- What it attaches to ----
            $table->foreignId('order_id')
                ->constrained('pos_orders')
                ->cascadeOnDelete();
            // NULL = order-level discount; set = one specific line.
            $table->foreignId('order_item_id')
                ->nullable()
                ->constrained('pos_order_items')
                ->nullOnDelete();

            // ---- Which rule (NULL = manual / ad-hoc, no catalogue rule) ----
            $table->foreignId('discount_id')
                ->nullable()
                ->constrained('pos_discounts')
                ->nullOnDelete();

            // Rule snapshot — survives rename / soft-delete for history.
            $table->string('name_snapshot');
            // 32-char string. App enum {@see App\Enums\DiscountAmountType}:
            // percent / fixed. Nullable for manual discounts that carry none.
            $table->string('amount_type_snapshot', 32)->nullable();

            // OMR value attributed to this rule (decimal:3 baisas precision).
            $table->decimal('amount', 12, 3)->default(0);

            // Business time the discount was applied (= order.opened_at).
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            // by-rule report scan: company + rule, ordered by spend.
            $table->index(['company_id', 'discount_id'], 'pos_order_discounts_company_rule_idx');
            // "this order's discounts" lookup.
            $table->index(['order_id'], 'pos_order_discounts_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_discounts');
    }
};
