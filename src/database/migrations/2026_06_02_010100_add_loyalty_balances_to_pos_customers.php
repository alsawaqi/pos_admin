<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6b — denormalised running totals on pos_customers.
 *
 * points_balance + wallet_balance are kept in lock-step with
 * SUM(point_ledger) + SUM(wallet_ledger) respectively, via
 * WritePointLedgerEntryAction + WriteWalletLedgerEntryAction
 * (the canonical entry points — every change goes through one
 * of them inside a DB transaction).
 *
 * Why denormalise instead of always summing the ledger:
 *   - Reads dominate writes by ~100x (every customer-detail
 *     view shows the balances; only paid sales + manual
 *     adjustments write entries).
 *   - The Customers list page shows balance chips for ALL
 *     customers in one query — summing per-row at render
 *     time would be O(N * ledger-size).
 *   - The (company_id, phone) Customers lookup at the POS
 *     needs a balance read with zero joins.
 *
 * Mirrors the pos_branch_stock.quantity pattern from Phase 5a.
 *
 * Defaults are zero; existing customers (from Phase 6a) start
 * with no balance, which is correct — they never earned/spent
 * anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_customers', function (Blueprint $table): void {
            // Points are integer (no fractional points in the
            // pilot). 4 bytes is plenty — even a 100-points-per-
            // visit power customer would take 10 million visits
            // to overflow.
            $table->integer('points_balance')->default(0)->after('phone');
            // Wallet is OMR with the project-wide decimal:3
            // precision. SIGNED — wallet can't go below 0 in
            // practice (the Action validates), but storing as
            // unsigned would make refund-flow corrections more
            // awkward without buying any safety.
            $table->decimal('wallet_balance', 12, 3)->default(0)->after('points_balance');
        });
    }

    public function down(): void
    {
        Schema::table('pos_customers', function (Blueprint $table): void {
            $table->dropColumn(['points_balance', 'wallet_balance']);
        });
    }
};
