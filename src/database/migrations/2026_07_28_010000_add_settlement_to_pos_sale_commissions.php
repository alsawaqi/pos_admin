<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission SETTLEMENT — estimate → settled.
 *
 * pos_api writes the per-sale split at order.pay using the merchant's
 * CONFIGURED percents (commission_amount = the ESTIMATE). For card sales the
 * bank (acquirer) slice is only an estimate — the real fee is not known until
 * the bank settles, and it varies (the bank may take more or less). The admin
 * later reconciles a batch of card orders against the bank's actual fee and
 * the merchant's exact net is finalised.
 *
 * commission_amount stays the immutable ESTIMATE. settled_amount holds the
 * RECONCILED figure once a settlement is applied:
 *   - bank row      → the actual fee allocated to this order
 *   - merchant row  → collected − platform − actual bank (the exact payable)
 *   - platform/other→ unchanged (= estimate; the platform cut is fixed)
 * Pass-through model: the merchant bears the bank-fee variance (their net
 * moves), the platform cut does not. NULL settled_amount = not yet reconciled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sale_commissions', function (Blueprint $table): void {
            // The reconciled amount for this party row (NULL until settled).
            $table->decimal('settled_amount', 12, 3)->nullable()->after('commission_amount');
            $table->boolean('is_settled')->default(false)->after('settled_amount');
            $table->timestamp('settled_at')->nullable()->after('is_settled');
            // The settlement event that reconciled this row (pos_commission_settlements).
            $table->unsignedBigInteger('settlement_id')->nullable()->after('payout_id');
            $table->index('settlement_id', 'pos_sale_commissions_settlement_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sale_commissions', function (Blueprint $table): void {
            $table->dropIndex('pos_sale_commissions_settlement_id_index');
            $table->dropColumn(['settled_amount', 'is_settled', 'settled_at', 'settlement_id']);
        });
    }
};
