<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One new column on pos_devices, captured by the admin at device
 * ASSIGNMENT time (same moment the bank hands over the terminal):
 *
 *   terminal_pin — Per-device Mosambee Soft-POS terminal login PIN,
 *                  issued by the acquiring bank alongside the
 *                  terminal_id. The device app uses it as the
 *                  Mosambee login `pin` argument; when unset the
 *                  device falls back to the vendor default (1321).
 *
 * Nullable because most existing terminals run on the default PIN —
 * only banks that issue a custom PIN populate it. Cleared back to
 * NULL when the device is unassigned (the PIN belongs to THIS
 * merchant's terminal binding, exactly like terminal_id).
 *
 * Stored as a PLAIN string deliberately — NO 'encrypted' cast. The
 * pos_devices table is shared with pos_api, which runs a different
 * APP_KEY in production; an encrypted cast written by pos_admin
 * would be unreadable there. The value is masked (never raw) in
 * audit logs instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            // 32 chars is generous — Mosambee PINs are short numerics
            // today, but banks vary so we don't over-constrain.
            $table->string('terminal_pin', 32)
                ->nullable()
                ->after('terminal_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->dropColumn('terminal_pin');
        });
    }
};
