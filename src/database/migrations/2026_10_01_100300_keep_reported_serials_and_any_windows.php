<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P1 B1 review follow-up. pos_api's test schema mirrors both columns.
 *
 *  - pos_device_activation_attempts.reported_serial: the full normalised
 *    serial a refused device reported. A hardware serial is not a secret, and
 *    the admin needs it to correct a mis-typed device record.
 *  - pos_devices.location_any_windows: every closed 'any' period of the
 *    current assignment ([{from, until}], newest last, capped at 20), so
 *    any → branch → any → branch never refuses queued sales made during an
 *    earlier 'any' period. The latest period is copied in from the two
 *    existing columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_device_activation_attempts', function (Blueprint $table): void {
            $table->string('reported_serial', 128)->nullable();
        });
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->json('location_any_windows')->nullable();
        });

        DB::table('pos_devices')->where('location_mode', 'branch')
            ->whereNotNull('location_any_started_at')->whereNotNull('location_mode_since')
            ->orderBy('id')->get(['id', 'location_any_started_at', 'location_mode_since'])
            ->each(fn ($row) => DB::table('pos_devices')->where('id', $row->id)->update([
                'location_any_windows' => json_encode([[
                    'from' => Carbon::parse($row->location_any_started_at)->toIso8601String(),
                    'until' => Carbon::parse($row->location_mode_since)->toIso8601String(),
                ]]),
            ]));
    }

    public function down(): void
    {
        Schema::table('pos_devices', fn (Blueprint $table) => $table->dropColumn('location_any_windows'));
        Schema::table('pos_device_activation_attempts', fn (Blueprint $table) => $table->dropColumn('reported_serial'));
    }
};
