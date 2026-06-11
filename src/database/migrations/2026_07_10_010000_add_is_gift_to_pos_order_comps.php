<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-F5 — per-item GIFTS as comp rows.
 *
 * A gifted line is a 100% write-off recorded in pos_order_comps with
 * is_gift = true, NO comp_reason_id (the column has been nullable with
 * a nullOnDelete FK since the Phase B create — no alteration needed)
 * and NO reason cap: the line was given away whole. Gift rows snapshot
 * the fixed 'gift'/'Gift' labels into the NOT NULL reason_*_snapshot
 * columns so history reads without a master reason row.
 *
 * Plain boolean add ⇒ SQLite-safe (pos_admin's test suite runs these
 * real migrations on sqlite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_comps', function (Blueprint $table): void {
            $table->boolean('is_gift')->default(false)->after('reason_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_comps', function (Blueprint $table): void {
            $table->dropColumn('is_gift');
        });
    }
};
