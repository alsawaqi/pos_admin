<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty refactor — loyalty accounts (blueprint §10.6).
 *
 * One account per (customer, rule). Holds the denormalised
 * running balances — stamp_count for visit_based rules,
 * point_balance for spend_based — kept in lock-step with
 * SUM(loyalty_transactions) by WriteLoyaltyTransactionAction
 * inside a row-locked transaction.
 *
 * A customer can hold several accounts (one per rule they've
 * engaged with), which is why points moved OFF
 * pos_customers.points_balance and onto this per-rule row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_loyalty_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Denormalised for tenant-scoped queries.
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('customer_id')
                ->constrained('pos_customers')
                ->cascadeOnDelete();
            $table->foreignId('loyalty_rule_id')
                ->constrained('pos_loyalty_rules')
                ->cascadeOnDelete();

            $table->integer('stamp_count')->default(0);
            $table->integer('point_balance')->default(0);
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();

            // One account per customer per rule.
            $table->unique(['customer_id', 'loyalty_rule_id'], 'pos_loyalty_accounts_customer_rule_unique');
            $table->index('company_id', 'pos_loyalty_accounts_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_loyalty_accounts');
    }
};
