<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — device offline-sync event log (blueprint §10.9).
 *
 * The append-only idempotency ledger for the device API's
 * /api/v1/device/sync/push. Every state-mutating action a device
 * performs (order, payment, void, donation, expense, restock,
 * shift) is an event with a client-generated UUID. The server
 * processes each EXACTLY once: a duplicate client_event_id is a
 * no-op that re-returns the original ACK, so a device replaying a
 * 4-hour-old offline batch settles correctly.
 *
 * Owned here (pos_admin owns the shared pos_* schema); pos_api
 * reads/writes it and mirrors it in its sqlite test schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sync_events', function (Blueprint $table): void {
            $table->id();
            // Client-generated UUID — the idempotency key. UNIQUE so a
            // replayed event collides and is treated as already-processed.
            $table->uuid('client_event_id')->unique();
            $table->foreignId('device_id')
                ->nullable()
                ->constrained('pos_devices')
                ->nullOnDelete();
            // e.g. order.create, order.pay, order.void, donation.record,
            // expense.log, restock.request, shift.open, shift.close.
            $table->string('event_type', 64);
            // The full event body the device sent. jsonb on Postgres.
            $table->jsonb('payload_json');
            // When the action happened ON THE DEVICE (may be hours before
            // it reached the server) vs. when the server received/processed it.
            $table->timestamp('client_timestamp');
            $table->timestamp('server_received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            // received / processed / failed / duplicate.
            $table->string('ack_status', 32)->default('received');
            // Canonical server-side entity the event produced (e.g. the
            // order id) + any error detail, for the ACK envelope.
            $table->jsonb('result_json')->nullable();

            $table->index(['device_id', 'server_received_at'], 'pos_sync_events_device_received_idx');
            $table->index('ack_status', 'pos_sync_events_ack_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sync_events');
    }
};
