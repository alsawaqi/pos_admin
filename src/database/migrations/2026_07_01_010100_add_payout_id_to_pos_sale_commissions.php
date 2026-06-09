<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 #17 (Phase B) — claim commissions into a payout.
 *
 * A nullable payout_id on the merchant-commission rows: creating a payout sets
 * it on the period's still-unclaimed merchant rows, so the SAME earnings can
 * never land in two payouts (double-pay guard). Cancelling a pending payout
 * clears it. Only the party_type='merchant' rows are ever claimed (the platform/
 * bank/other cuts aren't paid to the merchant). Plain indexed id, not an FK —
 * consistent with the table's other cross-concern ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sale_commissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('payout_id')->nullable()->after('commission_profile_id');
            $table->index('payout_id', 'pos_sale_commissions_payout_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sale_commissions', function (Blueprint $table): void {
            $table->dropIndex('pos_sale_commissions_payout_id_index');
            $table->dropColumn('payout_id');
        });
    }
};
