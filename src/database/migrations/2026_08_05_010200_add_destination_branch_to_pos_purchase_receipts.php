<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restock Phase B (owner decision 2026-07-28, scenario 3) — a supplier
 * van can deliver a purchase STRAIGHT TO A BRANCH, not to the central
 * warehouse. destination_branch_id records where the whole receipt
 * physically landed:
 *
 *   NULL       central warehouse (today's default — receive, then
 *              optionally distribute per line).
 *   <branch>   direct-to-branch: every line auto-allocates 100% to
 *              this branch through the existing receive+distribute
 *              pipeline (conserved ledger: received central +
 *              allocation_out/in pair, net central effect zero), with
 *              the full supplier/cost/credit/AP machinery unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_purchase_receipts', function (Blueprint $table): void {
            $table->foreignId('destination_branch_id')
                ->nullable()
                ->constrained('pos_branches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_purchase_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('destination_branch_id');
        });
    }
};
