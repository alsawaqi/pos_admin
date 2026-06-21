<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional branch scope on a payout.
 *
 * The daily reconciliation flow pays out one BRANCH at a time (the admin
 * reconciles a branch's card sales against its bank statement, then pays that
 * branch's net to the merchant). branch_id records which branch a payout
 * settled; NULL = a company-wide payout (the older per-merchant path still
 * works). Plain indexed id, like the table's other cross-concern ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: the dev DB picked up the column from an earlier partial
        // run. Guard so it records cleanly here and still applies on prod.
        if (Schema::hasColumn('pos_payouts', 'branch_id')) {
            return;
        }

        Schema::table('pos_payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
            $table->index('branch_id', 'pos_payouts_branch_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('pos_payouts', function (Blueprint $table): void {
            $table->dropIndex('pos_payouts_branch_id_index');
            $table->dropColumn('branch_id');
        });
    }
};
