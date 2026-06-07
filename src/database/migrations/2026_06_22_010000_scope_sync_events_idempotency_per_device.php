<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope device offline-sync idempotency PER DEVICE.
 *
 * The original pos_sync_events.client_event_id UNIQUE was global across the
 * whole multi-tenant DB, so one tenant's device could (by reusing or guessing
 * a client_event_id) suppress another tenant's event as a false "duplicate"
 * and read back its server-side order id via the duplicate ACK. The
 * idempotency key belongs to the device, so uniqueness must be
 * (device_id, client_event_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sync_events', function (Blueprint $table): void {
            $table->dropUnique(['client_event_id']);
            $table->unique(['device_id', 'client_event_id'], 'pos_sync_events_device_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sync_events', function (Blueprint $table): void {
            $table->dropUnique('pos_sync_events_device_event_unique');
            $table->unique('client_event_id');
        });
    }
};
