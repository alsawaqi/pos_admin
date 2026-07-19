<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HH-2 — per-staff shared shifts (open once a day, on any terminal).
 *
 * A shift opened by an HH-2-aware client (payload shared_shift: true) is
 * STAFF-KEYED: the same person's shift is adopted by every device they log
 * into (pos_machine + pos_handheld), and the close attributes money by the
 * staff member across those devices. Legacy shifts (deployed field builds
 * that predate the flag) keep the pure per-device drawer semantics — the
 * flag is what lets both generations coexist against one server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->boolean('is_shared')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->dropColumn('is_shared');
        });
    }
};
