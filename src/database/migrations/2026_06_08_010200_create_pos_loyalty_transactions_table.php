<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty refactor — loyalty transactions (blueprint §10.6).
 *
 * Append-only ledger for every loyalty movement. A single row may
 * carry a points_delta, a stamps_delta, or both (a visit_based
 * rule that also awards points). balance_after_* columns hold the
 * post-application running balances so the history view never
 * re-sums and ledger/account drift is caught instantly.
 *
 * Never updated, never deleted — corrections are NEW `adjust`
 * rows. The Phase 6b point ledger collapses into this table.
 *
 * order_id links earn/redeem to the triggering sale; it stays
 * nullable + unconstrained until Phase 8 wires the sale pipeline
 * (manual adjustments carry no order). recorded_by_user_id is the
 * portal user behind a manual adjustment (null for POS-driven).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_loyalty_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Denormalised so the §5.11.8 Customer Report sums by
            // company + window without joining through accounts.
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('loyalty_account_id')
                ->constrained('pos_loyalty_accounts')
                ->cascadeOnDelete();

            // 32-char string. App enum {@see App\Enums\LoyaltyTransactionType}:
            // earn / redeem / adjust / expire.
            $table->string('type', 32);

            // SIGNED deltas.
            $table->integer('points_delta')->default(0);
            $table->integer('stamps_delta')->default(0);
            $table->integer('balance_after_points')->default(0);
            $table->integer('balance_after_stamps')->default(0);

            $table->text('reason')->nullable();
            // Phase 8 wires this to pos_orders; nullable +
            // unconstrained for now.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();

            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['loyalty_account_id', 'occurred_at'], 'pos_loyalty_txns_account_occurred_idx');
            // The §5.11.8 Customer Report's points_issued / redeemed
            // window aggregate.
            $table->index(['company_id', 'occurred_at'], 'pos_loyalty_txns_company_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_loyalty_transactions');
    }
};
