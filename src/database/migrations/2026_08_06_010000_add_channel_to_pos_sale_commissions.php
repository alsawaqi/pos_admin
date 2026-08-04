<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mixed-tender apportionment — the money channel each commission row
 * belongs to.
 *
 *   'card'      — the SoftPOS card slice. The PLATFORM holds this money
 *                 (acquirer settles to the platform); the merchant is
 *                 paid its residual via payouts.
 *   'cash_bank' — the cash / bank-POS slice. The MERCHANT holds this
 *                 money in hand; the platform bills its commission via
 *                 commission invoices.
 *   'all'       — LEGACY rows recorded before channel-splitting (and any
 *                 rows written by not-yet-deployed recorders during the
 *                 rollout window). Every pre-existing code path treats
 *                 them exactly as before, so history is untouched.
 *
 * Why this exists: the claim ledger (payout_id / invoice_id) is per ROW,
 * but a MIXED order (card + cash on one bill) used to record ONE
 * whole-order merchant residual row. The payout claimed it whole and
 * paid the merchant the cash slice a second time — money the platform
 * never held (the confirmed leak this migration is step one of fixing).
 * Splitting rows per channel lets payouts claim only card-channel
 * residuals and invoices claim only cash-channel commission, with the
 * per-channel invariant Σ(rows in channel) == channel collected.
 *
 * Idempotent (hasColumn guard) — same precedent as invoice_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pos_sale_commissions', 'channel')) {
            return;
        }

        Schema::table('pos_sale_commissions', function (Blueprint $table): void {
            $table->string('channel', 16)->default('all')->after('party_label');
            $table->index(['order_id', 'channel'], 'pos_sale_commissions_order_channel_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sale_commissions', function (Blueprint $table): void {
            $table->dropIndex('pos_sale_commissions_order_channel_idx');
            $table->dropColumn('channel');
        });
    }
};
