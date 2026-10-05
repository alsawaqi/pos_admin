<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * scan geofence (qr_scan_geofence_mode) is a separate setting. pos_api
 * mirrors the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_branches', function (Blueprint $table): void {
            $table->boolean('location_check_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('pos_branches', function (Blueprint $table): void {
            $table->dropColumn('location_check_enabled');
        });
    }
};
