<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant resolution trail for API-001 loyalty-redemption shortfalls.
 *
 * The flagged zero-delta ADJUST in pos_loyalty_transactions remains immutable:
 * no review fields are added to, or updated on, the append-only ledger row.
 * Absence of a row here means the marker is pending review; the one immutable
 * row records who resolved it, when, and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_loyalty_shortfall_reviews')) {
            return;
        }

        Schema::create('pos_loyalty_shortfall_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('loyalty_transaction_id')
                ->unique('pos_loyalty_shortfall_reviews_txn_unique')
                ->constrained('pos_loyalty_transactions')
                ->cascadeOnDelete();
            $table->foreignId('resolved_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->text('resolution_note');
            $table->timestamp('resolved_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['company_id', 'resolved_at'],
                'pos_loyalty_shortfall_reviews_company_resolved_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_loyalty_shortfall_reviews');
    }
};
