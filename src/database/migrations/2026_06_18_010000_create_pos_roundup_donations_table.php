<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS-owned charity round-up donations.
 *
 * When a card payment rounds up, the rounded-off slice is donated to
 * charity. Rather than relax the charity-owned charity_transactions table
 * (whose device_id + country_id are NOT NULL and assume a CHARITY device),
 * round-ups are recorded here, in our own table, and charity reporting can
 * UNION this in later. The column shape deliberately mirrors
 * charity_transactions so that UNION is mechanical.
 *
 * pos_api's round-up writer (a port of the charity store_dhofar logic)
 * INSERTs a row here at payment time; pos_payments.charity_transaction_id
 * points back to it.
 *
 * Cross-concern ids (company/branch/device/order/payment/bank/commission/
 * geo) are plain indexed ids, not FKs, mirroring the pos_branches geo and
 * pos_payments precedents -- the donation row is written by the device
 * sync path and only ever read by the admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_roundup_donations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // POS origin -- always known when a round-up happens.
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('device_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('payment_id')->index();

            // Acquiring bank + commission snapshot (from the pos_device).
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->string('terminal_id')->nullable();
            $table->unsignedBigInteger('commission_profile_id')->nullable();

            // The donation.
            $table->decimal('amount', 12, 3);
            $table->jsonb('bank_response')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('source', 30)->default('pos_roundup');

            // Location snapshot (from the branch) for charity reporting.
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Offline-sync idempotency + when the round-up happened on device.
            $table->string('client_event_id', 64)->nullable()->unique();
            $table->timestamp('occurred_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'pos_roundup_company_created_index');
            $table->index('status', 'pos_roundup_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_roundup_donations');
    }
};
