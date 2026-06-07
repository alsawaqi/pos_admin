<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5a — Stock movements append-only ledger (blueprint §5.6.3 + §10.5).
 *
 * Every change to a branch's stock writes one row here. The
 * sum of quantity per (branch_id, ingredient_id) MUST equal
 * pos_branch_stock.quantity at all times — enforced by
 * WriteStockMovementAction wrapping both writes in one DB
 * transaction.
 *
 * Append-only: never updated, never deleted. Corrections are
 * NEW Adjustment rows with the delta needed to reach the
 * target. This gives a permanent forensic trail.
 *
 * Columns:
 *   movement_type    — initial / restock / sale_consumption /
 *                       addon_consumption / waste / loss /
 *                       adjustment / transfer_in / transfer_out.
 *                       Application enum gates inputs.
 *                       Phase 5a actions only emit: initial,
 *                       restock, adjustment. Sale/addon arrive
 *                       in Phase 8 (POS). Waste/loss in Phase
 *                       5c. Transfers in Phase 5c (allocation).
 *   quantity         — signed. Positive = inflow, negative =
 *                       outflow. decimal(12,3) for unit
 *                       precision parity with the ingredient.
 *   unit_cost_at_time — decimal(12,3) captures the unit cost
 *                       at the moment of this movement. Frozen
 *                       so historical COGS doesn't shift when
 *                       the ingredient's default cost changes
 *                       later. Phase 9 order line items
 *                       snapshot this via the recipe-snapshot
 *                       mechanism.
 *   reference_type / reference_id — polymorphic link to the
 *                       triggering entity: an Order, a
 *                       RestockRequest, a Transfer, etc. NULL
 *                       for manual adjustments.
 *   recorded_by_user_id / recorded_by_pos_staff_id — one of
 *                       these is set depending on who triggered
 *                       the movement. Portal user for manual
 *                       merchant-side entries; POS staff for
 *                       sale-driven consumption (Phase 8).
 *   note             — free-text reason, especially important
 *                       on Adjustment / Waste / Loss for the
 *                       audit trail.
 *   occurred_at      — when the merchant says the movement
 *                       happened (may differ from created_at
 *                       if a restock is logged a day late).
 *                       Reporting uses occurred_at for
 *                       date-bucket aggregation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();
            $table->string('movement_type', 32);
            // SIGNED — Postgres decimal handles negatives.
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost_at_time', 12, 3)->default(0);
            // Polymorphic — nullable when manual.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            // Exactly one of these should be set per row. We
            // allow both nullable rather than splitting into two
            // tables because the query patterns (audit
            // reconciliation, recent-movements report) want a
            // single ledger.
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->foreignId('recorded_by_pos_staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            // Hot paths: "movements at branch X over date range",
            // "movements for ingredient Y", and the polymorphic
            // lookback for an order's deductions.
            $table->index(['branch_id', 'occurred_at'], 'pos_stock_movements_branch_occurred_idx');
            $table->index(['ingredient_id', 'occurred_at'], 'pos_stock_movements_ingredient_occurred_idx');
            $table->index(['reference_type', 'reference_id'], 'pos_stock_movements_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_stock_movements');
    }
};
