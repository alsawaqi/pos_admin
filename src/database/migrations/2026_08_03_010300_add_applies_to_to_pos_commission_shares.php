<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-channel commission — a share line can now be scoped to a tender channel:
 *
 *   all       — bites every collected sale (the previous behaviour; default)
 *   card      — bites only the card-paid portion (like the bank line always has)
 *   cash_bank — bites only the cash/bank-POS portion (money the merchant holds)
 *
 * Lets the platform charge e.g. 1% on card sales and 2% on cash/bank-POS sales
 * as two separate lines. BANK lines are inherently card-only regardless of this
 * column (an acquirer fee can only exist on card money). Existing rows default
 * to 'all', so behaviour is unchanged until the admin opts in.
 *
 * Idempotent hasColumn guard, mirroring the table's other ALTERs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pos_commission_shares', 'applies_to')) {
            return;
        }

        Schema::table('pos_commission_shares', function (Blueprint $table): void {
            $table->string('applies_to', 20)->default('all')->after('percent');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pos_commission_shares', 'applies_to')) {
            return;
        }

        Schema::table('pos_commission_shares', function (Blueprint $table): void {
            $table->dropColumn('applies_to');
        });
    }
};
