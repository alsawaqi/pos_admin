<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_bank_softpos_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_id')->unique()->constrained('banks')->restrictOnDelete();
            $table->string('softpos_provider', 32);
            $table->string('softpos_package', 128)->nullable();
            $table->char('currency_code', 4)->default('0512');
            $table->boolean('refund_needs_transaction_id')->default(false);
            $table->boolean('void_needs_session_id')->default(false);
            $table->string('min_app_version', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('provider_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->string('softpos_provider', 32)->nullable();
            $table->string('softpos_package', 128)->nullable();
            $table->string('softpos_reported_provider', 32)->nullable();
            $table->boolean('softpos_mismatch')->default(false)->index();
            $table->string('softpos_mismatch_note', 255)->nullable();
            $table->string('softpos_transaction_id', 64)->nullable()->index();
            $table->string('softpos_rrn', 32)->nullable()->index();
            $table->string('softpos_batch_number', 16)->nullable();
            $table->string('softpos_card_masked', 24)->nullable();
            $table->string('softpos_card_type', 16)->nullable();
            $table->timestamp('softpos_receipt_at')->nullable();
            $table->decimal('refunded_total', 12, 3)->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('reversal_id')->nullable();
            // Sale amounts are positive; reversal ledger rows carry negative amounts.
            $table->string('direction', 8)->default('sale');
        });
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->string('card_tenders_blocked_reason', 64)->nullable();
            $table->timestamp('card_tenders_blocked_at')->nullable();
            $table->timestamp('card_tenders_unblocked_at')->nullable();
        });
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->decimal('refunded_total', 12, 3)->default(0);
        });

        Schema::create('pos_payment_reversals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('order_id')->constrained('pos_orders')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('pos_payments')->restrictOnDelete();
            $table->string('kind', 8);
            $table->decimal('amount', 12, 3);
            $table->integer('amount_baisas');
            $table->char('currency_code', 4);
            $table->string('status', 16)->default('pending');
            $table->string('softpos_provider', 32);
            $table->string('softpos_package', 128);
            $table->unsignedBigInteger('bank_id');
            $table->string('terminal_id', 64)->nullable();
            $table->string('original_softpos_transaction_id', 64)->nullable();
            $table->string('reversal_softpos_transaction_id', 64)->nullable();
            $table->string('reversal_rrn', 32)->nullable();
            $table->string('reversal_auth_code', 32)->nullable();
            $table->string('response_code', 16)->nullable();
            $table->string('response_description', 255)->nullable();
            $table->jsonb('receipt_json')->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->string('reason_note', 255)->nullable();
            $table->foreignId('void_reason_id')->nullable()->constrained('pos_void_reasons')->restrictOnDelete();
            $table->unsignedBigInteger('requested_by_staff_id')->nullable();
            $table->foreignId('approved_by_staff_id')->constrained('pos_staff')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('pos_devices')->restrictOnDelete();
            $table->string('client_request_id', 64);
            $table->string('request_fingerprint', 64);
            $table->timestamp('attempted_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->string('resolved_note', 255)->nullable();
            $table->unsignedBigInteger('ledger_payment_id')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'client_request_id'], 'pos_reversals_device_request_unique');
            $table->index(['device_id', 'status'], 'pos_reversals_device_status_idx');
            $table->index(['payment_id', 'status'], 'pos_reversals_payment_status_idx');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX pos_reversals_one_pending ON pos_payment_reversals (payment_id) WHERE status = 'pending'");
        }

        Schema::create('pos_payment_reversal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reversal_id')->constrained('pos_payment_reversals')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('pos_order_items')->restrictOnDelete();
            $table->decimal('qty', 10, 3);
            $table->decimal('amount', 12, 3);
            $table->integer('amount_baisas');
            $table->string('stock_mode_at_refund', 16);
            $table->boolean('returned_to_stock')->default(false);
            $table->unsignedBigInteger('product_stock_movement_id')->nullable();
            $table->unique(['reversal_id', 'order_item_id'], 'pos_reversal_lines_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payment_reversal_lines');
        Schema::dropIfExists('pos_payment_reversals');
        Schema::table('pos_orders', fn (Blueprint $table) => $table->dropColumn('refunded_total'));
        Schema::table('pos_devices', fn (Blueprint $table) => $table->dropColumn([
            'card_tenders_blocked_reason', 'card_tenders_blocked_at', 'card_tenders_unblocked_at',
        ]));
        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->dropIndex(['softpos_mismatch']);
            $table->dropIndex(['softpos_transaction_id']);
            $table->dropIndex(['softpos_rrn']);
            $table->dropColumn([
                'softpos_provider', 'softpos_package', 'softpos_reported_provider',
                'softpos_mismatch', 'softpos_mismatch_note', 'softpos_transaction_id',
                'softpos_rrn', 'softpos_batch_number', 'softpos_card_masked', 'softpos_card_type',
                'softpos_receipt_at', 'refunded_total', 'voided_at', 'reversal_id', 'direction',
            ]);
        });
        Schema::dropIfExists('pos_bank_softpos_profiles');
    }
};
