<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 follow-up 1 — how many times a shift was re-opened.
 *
 * A device closes a shift under a FIXED client_event_id: UUID v5 (URL
 * namespace 6ba7b811-9dad-11d1-80b4-00c04fd430c8) of
 * "shift-close:{shift_uuid}:{reopen_count}". The portal's re-open (Part B)
 * sets the shift open again, clears its close fields and increments
 * reopen_count, so the next close has a NEW id and gets a fresh Z, while a
 * repeat of the old close still returns the original Z from the sync ledger.
 * pos_api returns reopen_count wherever a device reads its open shift.
 *
 * Existing shifts start at 0. On Postgres a CHECK keeps it >= 0; SQLite (the
 * test mirror) enforces nothing. pos_api and pos_merchant mirror the column.
 */
return new class extends Migration
{
    private const CHECK = 'pos_shifts_reopen_count_check';

    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->integer('reopen_count')->default(0);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_shifts" ADD CONSTRAINT "'.self::CHECK.'" CHECK ("reopen_count" >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_shifts" DROP CONSTRAINT IF EXISTS "'.self::CHECK.'"');
        }

        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->dropColumn('reopen_count');
        });
    }
};
