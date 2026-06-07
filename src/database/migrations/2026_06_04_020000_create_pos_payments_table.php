<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7a — Payments (blueprint §10.8 + §16 Soft POS resolution).
 *
 * One row per tender. A single order can have multiple payments
 * (split tender: customer pays 2.000 OMR cash + 3.000 OMR card)
 * — invariant: SUM(payments.amount WHERE status=success) ==
 * order.grand_total for any order in status=paid.
 *
 * The "soft POS" reconciliation pattern from §16:
 *   - Cash payments settle fully offline. status flips
 *     straight to success on the device.
 *   - Card payments require bank connectivity (the bank's
 *     Soft POS APK). When the device is offline, the order
 *     can still record the INTENT (status=pending_reconciliation,
 *     pending_reconciliation=true) and the cashier completes
 *     the actual charge through Soft POS on reconnect.
 *   - reconciled_by_admin_id + reconciled_at capture the
 *     human-in-the-loop step when an admin matches a
 *     pending-recon row to a bank settlement file.
 *
 * methods (per blueprint §16 + the §10.8 enum):
 *   cash         — settles offline immediately
 *   card         — via bank Soft POS APK
 *   split_part   — one tender within a multi-tender order
 *   loyalty      — points / wallet redemption (Phase 6b)
 *   gift         — gift card / voucher
 *
 * softpos_reference + softpos_auth_code: opaque strings the bank
 * returns; reports cross-reference these against the bank's
 * settlement file.
 *
 * captured_at: when the bank actually authorised the charge
 * (which is different from when the customer tapped — there's
 * NFC-timeout retry in the field).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')
                ->constrained('pos_orders')
                ->cascadeOnDelete();

            // 32-char string. App enum
            // {@see App\Enums\PaymentMethod} restricts values:
            // cash / card / split_part / loyalty / gift.
            $table->string('method', 32);

            // POSITIVE amount. Multi-tender refunds (a partial
            // refund of a card payment) get a NEW payment row
            // with the refund Action tracking it -- they don't
            // mutate this one.
            $table->decimal('amount', 12, 3);
            // Cash overpayment → change given back. Only set
            // when method=cash. NULL for card / loyalty / gift.
            $table->decimal('change_given', 12, 3)->nullable();

            // ---- Soft POS round-trip (card payments only) ----
            // Bank reference + auth code. Nullable because cash
            // / loyalty / gift never round-trip the bank.
            $table->string('softpos_reference', 64)->nullable();
            $table->string('softpos_auth_code', 32)->nullable();

            // 32-char string. App enum
            // {@see App\Enums\PaymentStatus}: success /
            // pending_reconciliation / failed.
            $table->string('status', 32)->default('success');
            // Convenience boolean for the admin reconciliation
            // queue query — redundant with status but cheaper to
            // index ("show me everything pending recon" is a
            // hot path for the admin portal).
            $table->boolean('pending_reconciliation')->default(false);

            // ---- Admin-side reconciliation ----
            $table->foreignId('reconciled_by_admin_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();

            // When the device actually completed the tender.
            // Differs from created_at if a queued offline payment
            // syncs hours later.
            $table->timestamp('captured_at')->useCurrent();

            $table->timestamps();

            // Hot paths
            $table->index(['order_id'], 'pos_payments_order_idx');
            $table->index(['pending_reconciliation'], 'pos_payments_pending_recon_idx');
            // Reports group by method × time window.
            $table->index(['method', 'captured_at'], 'pos_payments_method_captured_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payments');
    }
};
