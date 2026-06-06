<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only per-sale commission breakdown.
 *
 * At order.pay, pos_api applies the merchant's commission profile to the
 * sale's grand_total and writes ONE row per party (platform, bank, any
 * other line, and the merchant residual). The party amounts sum exactly
 * to grand_total — each non-merchant party is rounded in baisas and the
 * merchant takes the exact remainder.
 *
 * Mirrors pos_roundup_donations: cross-concern ids (company/branch/device/
 * order/payment/profile) are plain indexed ids, not FKs, because the row
 * is written by the device-sync path and only ever read by the admin for
 * reporting. The profile + percent are SNAPSHOT so later edits to the
 * merchant's profile never rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sale_commissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // POS origin — always known when a sale is finalised.
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('device_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('payment_id')->nullable();

            // Snapshot of the profile that produced this split.
            $table->unsignedBigInteger('commission_profile_id')->nullable();

            // platform | bank | merchant | other.
            $table->string('party_type', 20);
            $table->string('party_label', 120);
            $table->decimal('percent', 5, 2);

            // gross = the sale's grand_total (same on every row of a sale);
            // commission = this party's slice. Σ(commission) == gross.
            $table->decimal('gross_amount', 12, 3);
            $table->decimal('commission_amount', 12, 3);

            // sort_order keeps the parties in profile order; together with
            // order_id it is the natural idempotency key for the sale's
            // breakdown (one row per party per order).
            $table->unsignedInteger('sort_order')->default(0);

            $table->string('client_event_id', 64)->nullable();
            $table->timestamp('occurred_at')->nullable();

            $table->timestamps();

            $table->unique(['order_id', 'sort_order'], 'pos_sale_commissions_order_sort_unique');
            $table->index(['company_id', 'created_at'], 'pos_sale_commissions_company_created_index');
            $table->index('party_type', 'pos_sale_commissions_party_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_commissions');
    }
};
