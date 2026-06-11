<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-F8 — merchant-defined order numbering: the printed receipt number on
 * the order row.
 *
 * The human-facing number (e.g. "KLD-0042") the POS printed on the
 * receipt — prefix + zero-padded counter allocated by pos_api's
 * POST /device/orders/next-number at payment time. NULLABLE on purpose:
 * orders rung before the feature, with numbering disabled, or queued
 * OFFLINE (the device's local fallback may not have reached the server
 * allocator) carry no server number — the wire contract tolerates an
 * order.create without one. NOT unique: a daily-reset sequence reuses
 * numbers across days by design.
 *
 * (company_id, receipt_number) is indexed for the merchant-portal lookup
 * path ("find order KLD-0042").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->string('receipt_number', 24)->nullable()->after('note');
            $table->index(['company_id', 'receipt_number'], 'pos_orders_company_receipt_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropIndex('pos_orders_company_receipt_idx');
            $table->dropColumn('receipt_number');
        });
    }
};
