<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 fix order 1 (F2) — the P5 marker is sticky per device.
 *
 * pos_devices.auth_v_seen_at is stamped by pos_api the first time a device
 * sends `auth_v: 1` (any sync event or online call). From then on pos_api
 * treats every request from that device as a P5 build's: a gated action with
 * no authorization block is `missing` (sync) or refused (online), never
 * `legacy`. Existing devices start NULL (not seen yet). pos_api and
 * pos_merchant mirror the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->timestamp('auth_v_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->dropColumn('auth_v_seen_at');
        });
    }
};
