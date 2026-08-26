<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make SoftPOS-reference reconciliation lookups index-backed.
 *
 * This is intentionally non-unique: historical references have not yet
 * been audited for duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->index('softpos_reference', 'pos_payments_softpos_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->dropIndex('pos_payments_softpos_ref_idx');
        });
    }
};
