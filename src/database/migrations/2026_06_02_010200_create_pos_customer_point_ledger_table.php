<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6b — points ledger (blueprint §6.2).
 *
 * Append-only. The sum of points_delta per (customer_id) MUST
 * equal pos_customers.points_balance at all times — enforced
 * by WritePointLedgerEntryAction wrapping both writes in one
 * DB transaction.
 *
 * Append-only invariant: never updated, never deleted.
 * Corrections are NEW Adjustment entries with the delta needed
 * to reach the target. This gives a forensic trail (who added
 * 50 points, when, why).
 *
 * Columns:
 *   entry_type    — earn / redeem / adjustment / refund_in /
 *                    expiry. App enum gates inputs. Phase 6b
 *                    actions only emit: adjustment. earn +
 *                    redeem arrive with Phase 8 (POS sale +
 *                    redemption). refund_in arrives with Phase
 *                    7+ order refunds. expiry is a future
 *                    background job.
 *   points_delta  — SIGNED integer. Positive on earn /
 *                    adjustment-up / refund_in. Negative on
 *                    redeem / adjustment-down / expiry.
 *   balance_after — denormalised running total at the moment
 *                    THIS entry landed. Lets the "points
 *                    history" view show the trail without
 *                    re-summing prior entries per row, and
 *                    surfaces ledger-customer drift instantly
 *                    if anything goes wrong (balance_after of
 *                    most recent entry should equal
 *                    customers.points_balance).
 *   reason        — free-text. Required by the action on
 *                    manual adjustments (so the audit trail
 *                    has context).
 *   reference_type/id — polymorphic link to the triggering
 *                    entity. Phase 8 will set this to an Order
 *                    on earn entries.
 *   company_id    — denormalised from the customer for the
 *                    tenant-scoped index (e.g. "all point
 *                    entries this month for my company").
 *                    Same rationale as Phase 5c restock
 *                    requests + Phase 6a vehicle plates.
 *   occurred_at   — when the action says the entry happened
 *                    (may differ from created_at for back-
 *                    dated entries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customer_point_ledger', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')
                ->constrained('pos_customers')
                ->cascadeOnDelete();
            // Denormalised from customer for tenant-scoped reports.
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->string('entry_type', 32);
            // SIGNED integer — Postgres handles negatives.
            $table->integer('points_delta');
            // Running balance after this entry landed.
            $table->integer('balance_after');
            $table->text('reason')->nullable();
            // Polymorphic — nullable when manual.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            // Hot paths: "this customer's history" + "tenant-wide
            // entries over a date range".
            $table->index(['customer_id', 'occurred_at'], 'pos_customer_point_ledger_customer_occurred_idx');
            $table->index(['company_id', 'occurred_at'], 'pos_customer_point_ledger_company_occurred_idx');
            $table->index(['reference_type', 'reference_id'], 'pos_customer_point_ledger_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_point_ledger');
    }
};
