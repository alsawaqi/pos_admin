<?php

use App\Support\DeviceSerial;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P1 B1 — device enrollment lock (decision 1a) + per-device location
 * mode (decision 2a). pos_api's test schema mirrors these columns.
 *
 *  - serial_verified_at: stamped by pos_api when an activation reported the
 *    same (normalised) hardware serial as the registered one.
 *  - location_mode: 'branch' (geofenced to the branch, default) | 'any'.
 *  - location_mode_since: when the current mode took effect.
 *  - location_any_started_at: start of the latest 'any' period; with
 *    location_mode_since it bounds the window in which a 'branch' device's
 *    late-arriving events were made while it was still 'any'.
 *
 * Existing serial numbers are normalised (trim, no whitespace, upper case) so
 * the existing unique index means "unique after normalisation". A collision
 * aborts the migration (nothing is written) instead of merging two devices.
 *
 * Existing assigned devices whose branch has no coordinates move to 'any':
 * they were unfenced before, and the server now refuses a 'branch' device at
 * a branch without a location instead of silently skipping the fence.
 */
return new class extends Migration
{
    public function up(): void
    {
        $normalised = [];
        $collisions = [];
        foreach (DB::table('pos_devices')->orderBy('id')->get(['id', 'serial_number']) as $row) {
            $serial = DeviceSerial::normalize($row->serial_number) ?? (string) $row->serial_number;
            if (isset($normalised[$serial])) {
                $collisions[] = $normalised[$serial].'/'.$row->id;
            }
            $normalised[$serial] = $row->id;
        }
        if ($collisions !== []) {
            throw new RuntimeException('Device serial numbers collide after normalisation (device ids '
                .implode(', ', $collisions).'). Correct them in pos_devices, then migrate again.');
        }

        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->timestamp('serial_verified_at')->nullable();
            $table->string('location_mode', 16)->default('branch');
            $table->timestamp('location_mode_since')->nullable();
            $table->timestamp('location_any_started_at')->nullable();
        });

        DB::transaction(function () use ($normalised): void {
            foreach ($normalised as $serial => $id) {
                // Array keys turn numeric serials into ints; compare as text.
                DB::table('pos_devices')->where('id', $id)->where('serial_number', '<>', (string) $serial)
                    ->update(['serial_number' => (string) $serial]);
            }

            $now = now();
            DB::table('pos_devices')->whereNotNull('branch_id')
                ->whereIn('branch_id', DB::table('pos_branches')
                    ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
                    ->select('id'))
                ->update(['location_mode' => 'any', 'location_mode_since' => $now, 'location_any_started_at' => $now]);
        });
    }

    public function down(): void
    {
        // Serial normalisation is deliberately not reversed.
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->dropColumn(['serial_verified_at', 'location_mode', 'location_mode_since', 'location_any_started_at']);
        });
    }
};
