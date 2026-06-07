<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5a — Per-branch current stock balance (blueprint §10.5).
 *
 * One row per (branch_id, ingredient_id). The 'quantity' column
 * is the running total that every stock movement adds to or
 * subtracts from inside a single DB transaction. Critical
 * invariant: this number must ALWAYS equal SUM(quantity) from
 * pos_stock_movements for the same (branch_id, ingredient_id)
 * — the WriteStockMovementAction enforces atomicity.
 *
 * Lazy creation: the row only exists once that ingredient has
 * had at least one movement at that branch. Merchants can have
 * an ingredient that exists company-wide but isn't stocked at
 * every branch — those have no branch_stock row, which the UI
 * renders as "Not Stocked".
 *
 * last_movement_at lets the "stale stock" report flag
 * ingredients that haven't moved in 30+ days (potential waste).
 *
 * No soft delete — when stock balance hits zero we keep the row
 * so the next restock doesn't have to re-create it. The
 * movements ledger carries the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_branch_stock', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();
            // Same unit semantics as ingredients.unit. decimal(12,3)
            // is huge — even tracking ingredients in grams,
            // 999,999,999.999 g is a billion kilos. Plenty for any
            // restaurant.
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            // Composite unique — one balance row per
            // (branch, ingredient). Composite lead on branch_id
            // for the common "show me stock at branch X" query.
            $table->unique(['branch_id', 'ingredient_id'], 'pos_branch_stock_branch_ingredient_unique');
            $table->index(['branch_id', 'quantity'], 'pos_branch_stock_branch_qty_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_branch_stock');
    }
};
