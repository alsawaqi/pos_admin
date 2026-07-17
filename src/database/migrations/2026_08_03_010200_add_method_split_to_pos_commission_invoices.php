<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B step 4 — show the merchant HOW the billed money was received.
 *
 * The invoice statement reads "you received X in cash and Y on the bank's POS —
 * this is our commission on it", so the header snapshots the billed orders'
 * collected money split by tender method (cash vs bank_pos). Nullable-free
 * defaults keep the migration safe on a populated table; existing invoices show
 * 0.000 (issued before the split existed).
 *
 * Idempotent hasColumn guard, mirroring add_invoice_id_to_pos_sale_commissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pos_commission_invoices', 'cash_gross')) {
            return;
        }

        Schema::table('pos_commission_invoices', function (Blueprint $table): void {
            $table->decimal('cash_gross', 12, 3)->default(0)->after('gross_amount');
            $table->decimal('bank_pos_gross', 12, 3)->default(0)->after('cash_gross');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pos_commission_invoices', 'cash_gross')) {
            return;
        }

        Schema::table('pos_commission_invoices', function (Blueprint $table): void {
            $table->dropColumn(['cash_gross', 'bank_pos_gross']);
        });
    }
};
