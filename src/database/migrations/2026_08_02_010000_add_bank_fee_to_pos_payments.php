<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A2 — captured actual bank fee (MDR) for a card tender.
     *
     * When an admin imports a bank settlement statement that carries the fee
     * (e.g. Oman Arab Bank's NET_AMOUNT column → fee = gross − net), the
     * reconciliation commit stores that per-transaction fee here. The commission
     * settlement worklist then PRE-FILLS the "actual bank" field from it, so the
     * operator cross-references the bank statement once (at import) instead of
     * re-keying every fee. NULL = no fee captured from a statement (the bank's
     * sheet lists gross only, e.g. Bank Dhofar) → the operator enters it manually.
     */
    public function up(): void
    {
        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->decimal('bank_fee', 12, 3)->nullable()->after('bank_response');
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->dropColumn('bank_fee');
        });
    }
};
