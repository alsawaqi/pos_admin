<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B — commission INVOICES (the merchant's settlement TO the platform).
 *
 * The reverse direction of pos_payouts. For a CASH or BANK_POS sale the money
 * went straight to the merchant, so the platform never held it — instead the
 * merchant OWES the platform its cut. An invoice aggregates a period's
 * un-invoiced platform + other commission rows (pos_sale_commissions with
 * party_type IN ('platform','other'), invoice_id NULL) for PURE cash/bank_pos
 * orders into ONE amount owed: total_owed = Σ those rows. The split is SNAPSHOT
 * (gross collected + platform/other/merchant) so the bill reads stand-alone.
 *
 * Direction is merchant → platform (total_owed is what the merchant remits;
 * unlike a payout, there is no bank-fee reconciliation — the platform cut is a
 * fixed percent already final in commission_amount, so an invoice is issued
 * straight from the estimate).
 *
 * Lifecycle: issued → paid (records reference + paid_at) | issued → void
 * (releases the claimed rows). A paid invoice is terminal. Cross-concern ids
 * (company / branch / users) are plain indexed ids, not FKs, mirroring
 * pos_payouts and the other POS money tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_commission_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();

            // The window the invoice bills (inclusive). Snapshotted on the row.
            $table->timestamp('period_from');
            $table->timestamp('period_to');

            // issued | paid | void.
            $table->string('status', 20)->default('issued');

            // Snapshot of the billed sales' split, in OMR. total_owed is the
            // amount the merchant remits (Σ claimed platform + other rows); the
            // rest describe the underlying cash/bank_pos sales for the statement.
            $table->decimal('gross_amount', 12, 3)->default(0);      // collected on the billed orders
            $table->decimal('platform_amount', 12, 3)->default(0);   // platform cut owed
            $table->decimal('other_amount', 12, 3)->default(0);      // other cut owed
            $table->decimal('merchant_amount', 12, 3)->default(0);   // what the merchant kept (informational)
            $table->decimal('total_owed', 12, 3)->default(0);        // platform + other = the bill
            $table->unsignedInteger('sales_count')->default(0);

            // Settlement evidence (filled when marked paid).
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('voided_by_user_id')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status'], 'pos_commission_invoices_company_status_idx');
            $table->index(['company_id', 'created_at'], 'pos_commission_invoices_company_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_commission_invoices');
    }
};
