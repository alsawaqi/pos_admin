<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank + charity round-up fields on pos_payments.
 *
 * The device / merchant write path populates these at card-payment time;
 * the admin reads them for bank reconciliation (terminal_id + bank_id +
 * the bank_response auth code) and to trace the charity round-up.
 *
 * roundup_amount = the slice of this tender rounded off for charity (the
 * merchant's sale amount stays on the order). It is recorded here only as
 * a POS-side breadcrumb -- the actual donation always lands in the shared
 * charity_transactions table, linked back via charity_transaction_id.
 *
 * bank_id -> banks, device_id -> pos_devices, charity_transaction_id ->
 * the charity-owned charity_transactions are intentionally plain indexed
 * ids (no FK), mirroring the pos_branches geo columns: they are soft,
 * cross-concern references the admin only reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_payments', function (Blueprint $table): void {
            // Bank round-trip detail (drives reconciliation matching).
            $table->jsonb('bank_response')->nullable()->after('softpos_auth_code');
            $table->string('terminal_id')->nullable()->after('bank_response');
            $table->unsignedBigInteger('bank_id')->nullable()->after('terminal_id');
            $table->unsignedBigInteger('device_id')->nullable()->after('bank_id');
            $table->decimal('latitude', 10, 7)->nullable()->after('device_id');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            // Charity round-up breadcrumb (donation lives in charity_transactions).
            $table->decimal('roundup_amount', 12, 3)->nullable()->after('longitude');
            $table->unsignedBigInteger('charity_transaction_id')->nullable()->after('roundup_amount');

            $table->index('bank_id', 'pos_payments_bank_id_index');
            $table->index('device_id', 'pos_payments_device_id_index');
            $table->index('terminal_id', 'pos_payments_terminal_id_index');
            $table->index('charity_transaction_id', 'pos_payments_charity_txn_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->dropIndex('pos_payments_bank_id_index');
            $table->dropIndex('pos_payments_device_id_index');
            $table->dropIndex('pos_payments_terminal_id_index');
            $table->dropIndex('pos_payments_charity_txn_id_index');
            $table->dropColumn([
                'bank_response',
                'terminal_id',
                'bank_id',
                'device_id',
                'latitude',
                'longitude',
                'roundup_amount',
                'charity_transaction_id',
            ]);
        });
    }
};
