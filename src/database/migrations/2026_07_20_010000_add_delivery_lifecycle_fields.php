<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-G7 — delivery-provider money lifecycle.
 *
 * Delivery-provider orders are not till sales: no tender is taken at the
 * POS — the provider pays later, minus their commission, and the sale only
 * becomes real when the merchant reconciles the provider's statement.
 *
 * pos_delivery_providers.commission_percent — the provider's cut (decimal
 * 5,2 like every other percent column). Default 0 keeps existing providers
 * commission-free until the merchant sets it.
 *
 * pos_orders gains the delivery lifecycle, following the established
 * order-resident precedents (plate_number / void_reason_id+label /
 * receipt_number):
 *
 *   delivery_provider_id / _provider_name — real FK (the 2026_06_03
 *     provider migration promised this linkage) + a name snapshot that
 *     survives provider rename/soft-delete, mirroring void_reason_label.
 *     Until now the device stuffed the provider name into `note`.
 *   delivery_reference        — the PROVIDER's order number (required at
 *     punch on the device; DB-nullable like receipt_number/client_event_id
 *     so historical rows stay valid).
 *   delivery_customer_phone / delivery_driver_phone — optional contact
 *     numbers captured in the Proceed popup (plate_number-style).
 *   delivery_commission_percent — SNAPSHOT of the provider % at punch time
 *     (the master may change before the statement arrives).
 *   delivery_expected_payout   — punched grand_total minus commission,
 *     frozen at punch so the Deliveries page needs no joins.
 *   delivery_received_amount / delivery_variance — what the provider
 *     actually paid + (received - expected), set at confirmation.
 *   delivery_punched_at        — the original punch moment. Revenue is
 *     dated to CONFIRMATION (the order's opened_at is re-stamped when the
 *     money is confirmed, so every opened_at-windowed report counts it in
 *     the period the money arrived); this column preserves the truth of
 *     when the order was actually rung up.
 *   delivery_confirmed_at / delivery_confirmed_by_user_id — the
 *     reconciliation actor + moment (reconciled_by/reconciled_at
 *     precedent from the P-F7 pending-tender flow).
 *
 * The pending state itself is a NEW order status value
 * ('pending_verification') — statuses are PHP-enum gated strings with no
 * DB constraint, so no DDL is needed for it. status='paid' is what every
 * revenue query filters on, which keeps pending deliveries out of revenue
 * everywhere by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_delivery_providers', function (Blueprint $table): void {
            $table->decimal('commission_percent', 5, 2)->default(0)->after('color');
        });

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->foreignId('delivery_provider_id')
                ->nullable()
                ->after('receipt_number')
                ->constrained('pos_delivery_providers')
                ->nullOnDelete();
            $table->string('delivery_provider_name', 64)->nullable()->after('delivery_provider_id');
            $table->string('delivery_reference', 64)->nullable()->after('delivery_provider_name');
            $table->string('delivery_customer_phone', 32)->nullable()->after('delivery_reference');
            $table->string('delivery_driver_phone', 32)->nullable()->after('delivery_customer_phone');
            $table->decimal('delivery_commission_percent', 5, 2)->nullable()->after('delivery_driver_phone');
            $table->decimal('delivery_expected_payout', 12, 3)->nullable()->after('delivery_commission_percent');
            $table->decimal('delivery_received_amount', 12, 3)->nullable()->after('delivery_expected_payout');
            $table->decimal('delivery_variance', 12, 3)->nullable()->after('delivery_received_amount');
            $table->timestamp('delivery_punched_at')->nullable()->after('delivery_variance');
            $table->timestamp('delivery_confirmed_at')->nullable()->after('delivery_punched_at');
            $table->foreignId('delivery_confirmed_by_user_id')
                ->nullable()
                ->after('delivery_confirmed_at')
                ->constrained('pos_users')
                ->nullOnDelete();

            $table->index(
                ['company_id', 'delivery_provider_id'],
                'pos_orders_company_provider_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropIndex('pos_orders_company_provider_idx');
            $table->dropConstrainedForeignId('delivery_confirmed_by_user_id');
            $table->dropColumn([
                'delivery_confirmed_at',
                'delivery_punched_at',
                'delivery_variance',
                'delivery_received_amount',
                'delivery_expected_payout',
                'delivery_commission_percent',
                'delivery_driver_phone',
                'delivery_customer_phone',
                'delivery_reference',
                'delivery_provider_name',
            ]);
            $table->dropConstrainedForeignId('delivery_provider_id');
        });

        Schema::table('pos_delivery_providers', function (Blueprint $table): void {
            $table->dropColumn('commission_percent');
        });
    }
};
