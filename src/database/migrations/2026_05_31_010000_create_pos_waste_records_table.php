<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5c — Waste records (blueprint §5.6.4 + §10.5).
 *
 * One row per waste event. The matching stock_movement (type
 * = waste, signed-negative quantity) points back here via
 * reference_type=WasteRecord + reference_id=this.id, giving
 * two complementary views of the same data:
 *
 *   - pos_waste_records: the QUERYABLE view used by the Waste
 *     tab + waste reports. Reason taxonomy is here.
 *   - pos_stock_movements: the LEDGER view — sum of quantity
 *     by branch+ingredient still has to equal branch_stock.
 *
 * Why a dedicated table instead of just stock_movements with
 * a reason column:
 *   - Waste reason is a closed enum the merchant categorises
 *     against (expired, spoiled, broken, dropped, contamination,
 *     other). Reporting by reason is the primary use case.
 *   - We want to keep stock_movements lean — it's a fast-growing
 *     append-only ledger and adding wide nullable columns there
 *     would hurt query performance over time.
 *
 * No soft delete: a waste record is a real-world event that
 * happened — to "remove" one, the merchant records a balancing
 * Adjustment movement (positive, with a note explaining the
 * correction). This keeps the forensic trail intact.
 *
 * Columns:
 *   quantity        — ALWAYS POSITIVE (the absolute amount
 *                      wasted). The stock_movement that mirrors
 *                      this row stores it as negative. Storing
 *                      positive here lets reports do
 *                      SUM(quantity) without an ABS() call.
 *   reason          — closed enum: expired / spoiled / broken /
 *                      dropped / contamination / other. NEVER NULL.
 *   notes           — free text. Required when reason='other'
 *                      (validation in the Action), optional
 *                      otherwise.
 *   unit_cost_at_time — frozen at the time of recording so the
 *                      "cost of waste" report doesn't shift when
 *                      the ingredient's default cost changes
 *                      later. Sourced from
 *                      ingredient.default_unit_cost.
 *   occurred_at     — when the WASTE happened (may predate
 *                      created_at if logged retroactively).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_waste_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();
            // ALWAYS POSITIVE — see column comment.
            $table->decimal('quantity', 12, 3);
            // 32 chars handles the longest current enum
            // ('contamination') with headroom for future
            // additions without a migration.
            $table->string('reason', 32);
            // unit_at_set is denormalised from the ingredient
            // (same defensive pattern as recipe lines) — survives
            // future unit edits.
            $table->string('unit_at_set', 16);
            $table->decimal('unit_cost_at_time', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            // Hot path: "waste at branch X over date range" + the
            // by-reason aggregation.
            $table->index(['branch_id', 'occurred_at'], 'pos_waste_records_branch_occurred_idx');
            $table->index(['ingredient_id', 'occurred_at'], 'pos_waste_records_ingredient_occurred_idx');
            $table->index(['reason'], 'pos_waste_records_reason_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_waste_records');
    }
};
