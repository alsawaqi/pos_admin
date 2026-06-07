<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6b — wallet ledger (blueprint §6.2).
 *
 * Same shape as the point ledger; differs in the value type:
 *   - amount_delta is decimal(12,3) OMR (signed)
 *   - balance_after is decimal(12,3) OMR
 *
 * Append-only. The sum of amount_delta per (customer_id) MUST
 * equal pos_customers.wallet_balance at all times — enforced
 * by WriteWalletLedgerEntryAction wrapping both writes in one
 * DB transaction.
 *
 * entry_type catalogue:
 *   topup           — manual admin "received 5 OMR cash, credit"
 *   redemption_use  — Phase 8+ customer applies wallet at POS
 *   adjustment      — manual admin correction (signed)
 *   refund_in       — Phase 7+ order refund flows here instead
 *                      of back-to-cash
 *
 * Phase 6b actions only emit topup + adjustment; the rest
 * arrive with later phases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customer_wallet_ledger', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')
                ->constrained('pos_customers')
                ->cascadeOnDelete();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->string('entry_type', 32);
            // SIGNED — positive on topup / refund_in / adjustment-
            // up; negative on redemption_use / adjustment-down.
            $table->decimal('amount_delta', 12, 3);
            // Running balance after this entry landed.
            $table->decimal('balance_after', 12, 3);
            $table->text('reason')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_id', 'occurred_at'], 'pos_customer_wallet_ledger_customer_occurred_idx');
            $table->index(['company_id', 'occurred_at'], 'pos_customer_wallet_ledger_company_occurred_idx');
            $table->index(['reference_type', 'reference_id'], 'pos_customer_wallet_ledger_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_wallet_ledger');
    }
};
