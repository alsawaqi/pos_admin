<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restock Phase A (owner decision 2026-07-28) — a fulfilled restock
 * request must say HOW the shortage was resolved, because the stock
 * effects differ:
 *
 *   resolution = 'warehouse'  HQ sent goods from the central pool —
 *                             fulfilment writes the paired central-
 *                             debit + branch-credit movements.
 *   resolution = 'purchase'   the goods entered through a purchase
 *                             record instead (branch bought outside,
 *                             or a supplier delivered directly) — the
 *                             request closes with NO stock movement of
 *                             its own, so the stock is never counted
 *                             twice.
 *
 * NULL = fulfilled before this column existed (legacy rows, which
 * credited the branch without debiting anything).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_restock_requests', function (Blueprint $table): void {
            $table->string('resolution', 16)->nullable();
            $table->string('resolution_note', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_restock_requests', function (Blueprint $table): void {
            $table->dropColumn(['resolution', 'resolution_note']);
        });
    }
};
