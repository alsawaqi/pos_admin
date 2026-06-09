<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 #17 (Phase B) — merchant payouts (the platform's settlement to a merchant).
 *
 * A payout aggregates a date range's UNSETTLED merchant-commission rows
 * (pos_sale_commissions.party_type='merchant', payout_id NULL) into ONE payable:
 * net_amount = Σ those rows. The full split is SNAPSHOT (gross + platform/bank/
 * other) so the statement reads stand-alone even if the underlying ledger later
 * changes. Direction is platform → merchant (net_amount is what the platform
 * pays the merchant; the platform keeps the platform cut, the acquirer the bank
 * cut).
 *
 * Lifecycle: pending → paid (records reference + paid_at) | pending → cancelled
 * (releases the claimed rows). A paid payout is terminal. Cross-concern ids
 * (company / users) are plain indexed ids, not FKs, mirroring the other POS
 * money tables (pos_sale_commissions, pos_roundup_donations) that the device-
 * sync path writes and the admin reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_payouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            // The window the payout settles (inclusive). Snapshotted on the row.
            $table->timestamp('period_from');
            $table->timestamp('period_to');

            // pending | paid | cancelled.
            $table->string('status', 20)->default('pending');

            // Snapshot of the claimed sales' split, in OMR. net_amount is the
            // merchant payable (Σ claimed merchant rows); the rest are the
            // deductions the merchant did NOT receive (for the statement).
            $table->decimal('gross_amount', 12, 3)->default(0);
            $table->decimal('platform_amount', 12, 3)->default(0);
            $table->decimal('bank_amount', 12, 3)->default(0);
            $table->decimal('other_amount', 12, 3)->default(0);
            $table->decimal('net_amount', 12, 3)->default(0);
            $table->unsignedInteger('sales_count')->default(0);

            // Settlement evidence (filled when marked paid).
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status'], 'pos_payouts_company_status_idx');
            $table->index(['company_id', 'created_at'], 'pos_payouts_company_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payouts');
    }
};
