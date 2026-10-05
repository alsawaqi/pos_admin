<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 add-on (owner request 2026-10-05) — a branch-level "Location
 * check" switch.
 *
 * pos_branches.location_check_enabled: true (the default, every existing
 * branch) keeps today's location rules; false makes the branch "open": staff
 * can log in and sell from any location, and the branch's map location is
 * kept. pos_api's GeofenceGuard skips every location rule for such a branch
 * and sends its devices location_mode "any" in the config. The QR customer
 * scan geofence (qr_scan_geofence_mode) is a separate setting.
 *
 * The switch keeps its history, like a device's 'any' periods
 * (pos_devices.location_any_windows):
 *   location_check_off_since   when the check was turned off (NULL while on)
 *   location_check_off_windows every closed off period, newest last, capped
 *                              at 50: [{"from": ISO-8601, "until": ISO-8601}]
 * so a sale made offline while the check was off, and synced after it was
 * turned back on, is still not fenced.
 *
 * On Postgres two CHECKs keep the history sound: the check is off exactly
 * when off_since is set, and the windows are a JSON array. pos_api mirrors
 * the columns.
 */
return new class extends Migration
{
    private const STATE_CHECK = 'pos_branches_location_check_off_since_check';

    private const WINDOWS_CHECK = 'pos_branches_location_check_off_windows_check';

    public function up(): void
    {
        Schema::table('pos_branches', function (Blueprint $table): void {
            $table->boolean('location_check_enabled')->default(true);
            $table->timestamp('location_check_off_since')->nullable();
            $table->json('location_check_off_windows')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_branches" ADD CONSTRAINT "'.self::STATE_CHECK.'" CHECK '
                .'("location_check_enabled" = ("location_check_off_since" IS NULL))');
            DB::statement('ALTER TABLE "pos_branches" ADD CONSTRAINT "'.self::WINDOWS_CHECK.'" CHECK '
                .'("location_check_off_windows" IS NULL OR json_typeof("location_check_off_windows") = \'array\')');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_branches" DROP CONSTRAINT IF EXISTS "'.self::WINDOWS_CHECK.'"');
            DB::statement('ALTER TABLE "pos_branches" DROP CONSTRAINT IF EXISTS "'.self::STATE_CHECK.'"');
        }

        Schema::table('pos_branches', function (Blueprint $table): void {
            $table->dropColumn(['location_check_enabled', 'location_check_off_since', 'location_check_off_windows']);
        });
    }
};
