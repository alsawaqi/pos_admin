<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission settlement events — the audit header for one reconciliation of a
 * merchant's card sales against the bank's ACTUAL fee.
 *
 * One row records: which merchant (+ optional branch) + window was settled, the
 * card gross, the ESTIMATED bank fee (Σ of the orders' estimated bank rows), the
 * ACTUAL bank fee the admin applied (from the bank statement, hand-overridable),
 * the resulting merchant net, and the variance (actual − estimated). The
 * per-order detail lives on pos_sale_commissions.settled_amount + settlement_id.
 *
 * source = manual (admin picks merchant + period, types the actual fee) |
 * bank_file (derived from an imported bank statement). status = applied |
 * reversed. A settlement can be reversed only while none of its orders have been
 * claimed into a payout. Cross-concern ids (company / branch / bank / users) are
 * plain indexed ids, not FKs — consistent with the other POS money tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_commission_settlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            // NULL = all branches of the merchant in the window.
            $table->unsignedBigInteger('branch_id')->nullable()->index();

            // manual | bank_file.
            $table->string('source', 20)->default('manual');
            // Set when source = bank_file.
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->date('statement_date')->nullable();

            // The window the settlement covers (by the orders' occurred_at).
            $table->timestamp('period_from')->nullable();
            $table->timestamp('period_to')->nullable();

            // Snapshot, in OMR. variance = actual_bank − estimated_bank (the
            // amount the merchant's net moved vs the estimate).
            $table->decimal('card_gross', 12, 3)->default(0);
            $table->decimal('estimated_bank', 12, 3)->default(0);
            $table->decimal('actual_bank', 12, 3)->default(0);
            $table->decimal('platform_total', 12, 3)->default(0);
            $table->decimal('merchant_net', 12, 3)->default(0);
            $table->decimal('variance', 12, 3)->default(0);
            $table->unsignedInteger('orders_count')->default(0);

            // applied | reversed.
            $table->string('status', 20)->default('applied');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('reversed_by_user_id')->nullable();
            $table->timestamp('reversed_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'pos_commission_settlements_company_created_idx');
            $table->index(['company_id', 'status'], 'pos_commission_settlements_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_commission_settlements');
    }
};
