<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permit future printed-card credentials without a station (T9). T2 has no
 * device-less writer; existing API station gates remain fail-closed (R9).
 * The device FK retains ON DELETE CASCADE: seatings, not credentials, outlive
 * hard-deleted opening stations. Only device_id's nullability changes here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropPartialIndexBeforeSqliteRebuild();

        Schema::table('pos_qr_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('device_id')->nullable()->change();
        });

        $this->restorePartialIndex();
    }

    public function down(): void
    {
        // Best effort: future device-less credentials cannot satisfy NOT NULL.
        DB::table('pos_qr_sessions')->whereNull('device_id')->delete();
        $this->dropPartialIndexBeforeSqliteRebuild();

        Schema::table('pos_qr_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('device_id')->nullable(false)->change();
        });

        $this->restorePartialIndex();
    }

    private function dropPartialIndexBeforeSqliteRebuild(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // Laravel rebuilds without the WHERE clause. Drop first so legal
            // duplicate terminal rows do not fail before repair is reached.
            DB::statement('DROP INDEX IF EXISTS pos_qr_sessions_table_live_unique');
        }
    }

    private function restorePartialIndex(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS pos_qr_sessions_table_live_unique');
            DB::statement(
                "CREATE UNIQUE INDEX pos_qr_sessions_table_live_unique
                 ON pos_qr_sessions (table_id)
                 WHERE table_id IS NOT NULL
                   AND status IN ('pending', 'active', 'ordered')"
            );
        }
    }
};
