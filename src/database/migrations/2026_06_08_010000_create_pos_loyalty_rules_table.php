<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty refactor — loyalty rules (blueprint §5.8 + §10.6).
 *
 * Replaces the Phase 6b single-config-per-company model with the
 * blueprint's multi-rule model: a company defines any number of
 * visit_based (stamp card) and/or spend_based (points) rules,
 * multiple active in parallel, each pause/resume-able.
 *
 * config_json holds the per-type configuration AND the §5.8
 * restrictions, so the rule row stays schema-stable as the
 * config surface evolves:
 *   visit_based: { min_order_value, stamps_required,
 *                  reward_type, reward_value, reward_product_id }
 *   spend_based: { points_per_omr, redemption_points,
 *                  redemption_value, min_redemption_points,
 *                  expiry_days }
 *   restrictions (both): { eligible_product_ids, eligible_category_ids,
 *                  branch_scope_json, dayofweek_mask, time_start,
 *                  time_end, max_redemption_per_order, customer_tag }
 *
 * Soft delete: loyalty_transactions reference the rule's account
 * for historical reports, so a retired rule soft-deletes.
 *
 * This migration OWNS the shared table; pos_merchant mirrors it
 * in its sqlite test schema and owns the write path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_loyalty_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();

            $table->string('name');
            // 32-char string. App enum {@see App\Enums\LoyaltyRuleType}:
            // visit_based / spend_based.
            $table->string('type', 32);
            // Per-type config + §5.8 restrictions.
            $table->json('config_json')->nullable();

            $table->timestamp('validity_start')->nullable();
            $table->timestamp('validity_end')->nullable();

            // 32-char string. App enum {@see App\Enums\LoyaltyRuleStatus}:
            // active / paused.
            $table->string('status', 32)->default('active');

            $table->timestamps();
            $table->softDeletes();

            // "active rules for company X" — the POS picker at
            // sale time + the rules-config list.
            $table->index(['company_id', 'status'], 'pos_loyalty_rules_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_loyalty_rules');
    }
};
