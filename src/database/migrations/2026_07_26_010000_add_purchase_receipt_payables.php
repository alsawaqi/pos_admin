<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AP — supplier credit + accounts payable on the Goods Received Note.
 *
 * The merchant wants to record that a delivery was bought ON CREDIT (the
 * supplier is paid later), see every receipt that is not yet fully paid, and
 * log each payment — even a partial one — against the receipt until it is
 * settled, keeping a history of how it was paid down.
 *
 * This is a pure SETTLEMENT / cash-timing layer. It does NOT change the
 * cash-model P&L: a receipt ALREADY books its full GROSS cost as categorized
 * pos_expenses rows at received_at (PD5/PD6), so net_profit reflects the whole
 * purchase the day the goods arrive — regardless of when the supplier is paid.
 * A payment recorded here therefore must NOT book another expense (that would
 * double-count); it only moves the receipt's outstanding balance.
 *
 * Header gains:
 *   - is_credit       — was this delivery bought on credit (pay later)?
 *   - amount_paid      — running total paid so far (frozen grand_total is owed).
 *   - payment_status   — 'paid' | 'partial' | 'unpaid' (denormalised, like the
 *                        loyalty account's running balance).
 *   - due_date         — optional date the supplier expects payment by.
 *
 * New child table pos_purchase_receipt_payments — the append-only payment
 * history (one row per payment, with the balance left after it), mirroring the
 * loyalty-transactions ledger shape.
 *
 * Existing receipts predate credit and were paid at receive in the old flow, so
 * they are backfilled to fully-paid (amount_paid = grand_total).
 *
 * Schema owner: pos_admin. Mirrored into pos_merchant's test schema. No pos_api
 * / pos_machine change — accounts payable is a portal data-entry concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_purchase_receipts', function (Blueprint $table): void {
            $table->boolean('is_credit')->default(false)->after('status');
            $table->decimal('amount_paid', 12, 3)->default(0)->after('is_credit');
            // 'paid' (settled at/after receive) | 'partial' | 'unpaid'.
            $table->string('payment_status', 16)->default('paid')->after('amount_paid');
            $table->date('due_date')->nullable()->after('payment_status');
        });

        // Existing receipts were settled at receive time under the old flow —
        // mark them fully paid so they never surface as "outstanding".
        DB::table('pos_purchase_receipts')->update([
            'amount_paid' => DB::raw('grand_total'),
            'payment_status' => 'paid',
        ]);

        Schema::create('pos_purchase_receipt_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('purchase_receipt_id')
                ->constrained('pos_purchase_receipts')
                ->cascadeOnDelete();
            // The amount paid in this settlement (OMR, 3-decimal baisas).
            $table->decimal('amount', 12, 3);
            // The outstanding balance LEFT after this payment (grand_total −
            // running amount_paid) — a snapshot so the history reads back.
            $table->decimal('balance_after', 12, 3);
            // Optional free label for how it was paid ("cash", "bank transfer").
            $table->string('method', 32)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->timestamp('paid_at')->useCurrent();
            $table->timestamps();
            $table->index(['purchase_receipt_id'], 'pos_purchase_receipt_payments_receipt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_purchase_receipt_payments');

        Schema::table('pos_purchase_receipts', function (Blueprint $table): void {
            $table->dropColumn(['is_credit', 'amount_paid', 'payment_status', 'due_date']);
        });
    }
};
