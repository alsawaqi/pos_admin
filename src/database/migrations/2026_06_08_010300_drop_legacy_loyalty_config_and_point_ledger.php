<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty refactor — retire the Phase 6b single-config + point
 * ledger model, now superseded by pos_loyalty_rules / _accounts /
 * _transactions.
 *
 *   - drop pos_customer_point_ledger      → pos_loyalty_transactions
 *   - drop pos_customer_loyalty_configs   → a spend_based rule
 *   - drop pos_customers.points_balance   → pos_loyalty_accounts.point_balance
 *
 * The WALLET side (pos_customer_wallet_ledger + customers
 * .wallet_balance) is a separate store-credit feature, NOT part of
 * the blueprint loyalty model, and is deliberately untouched.
 *
 * Safe to run destructively: pre-production, no live loyalty data.
 * down() recreates the dropped structures (empty) for reversibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pos_customer_point_ledger');
        Schema::dropIfExists('pos_customer_loyalty_configs');

        if (Schema::hasColumn('pos_customers', 'points_balance')) {
            Schema::table('pos_customers', function (Blueprint $table): void {
                $table->dropColumn('points_balance');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pos_customers', 'points_balance')) {
            Schema::table('pos_customers', function (Blueprint $table): void {
                $table->integer('points_balance')->default(0);
            });
        }

        Schema::create('pos_customer_loyalty_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('pos_companies')->cascadeOnDelete();
            $table->unsignedInteger('points_per_omr')->default(0);
            $table->unsignedInteger('baisas_per_point')->default(10);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_customer_point_ledger', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('pos_customers')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('entry_type', 32);
            $table->integer('points_delta');
            $table->integer('balance_after');
            $table->text('reason')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });
    }
};
