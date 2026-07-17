<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B — claim commissions into an invoice (the reverse of payout_id).
 *
 * A nullable invoice_id on the commission rows: issuing an invoice sets it on the
 * period's still-unclaimed platform + other rows of pure cash/bank_pos orders, so
 * the SAME commission can never be billed twice (double-bill guard). Voiding an
 * issued invoice clears it. Only the party_type IN ('platform','other') rows are
 * ever claimed here (the merchant residual is what the merchant KEEPS, and card
 * rows go through the payout/settlement path instead). Plain indexed id, not an
 * FK — consistent with payout_id and the table's other cross-concern ids.
 *
 * Idempotent hasColumn guard: the dev DB has occasionally drifted (column present
 * but the migration unrecorded), so re-running must be safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pos_sale_commissions', 'invoice_id')) {
            return;
        }

        Schema::table('pos_sale_commissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_id')->nullable()->after('settlement_id');
            $table->index('invoice_id', 'pos_sale_commissions_invoice_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pos_sale_commissions', 'invoice_id')) {
            return;
        }

        Schema::table('pos_sale_commissions', function (Blueprint $table): void {
            $table->dropIndex('pos_sale_commissions_invoice_id_index');
            $table->dropColumn('invoice_id');
        });
    }
};
