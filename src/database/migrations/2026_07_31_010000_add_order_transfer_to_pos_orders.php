<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device-to-device order transfer.
 *
 * An unpaid order (status=held mirror) can be handed from one device to
 * another IN THE SAME BRANCH — main POS ↔ handheld — so a cashier can start a
 * sale on one terminal and finish the payment on another. The transfer is
 * server-mediated: the source device parks the order addressed to a target,
 * the target lists its incoming transfers and CLAIMS one into its cart.
 *
 * Three order-resident columns, following the plate_number / void_reason /
 * receipt_number precedent (nullable so every historical row stays valid):
 *
 *   transferred_to_device_id   — the device an IN-FLIGHT transfer is addressed
 *     to. Set when the source sends; CLEARED when the target claims (or the
 *     source cancels). A held order with this non-null is "waiting for" that
 *     device; it disappears from the sender and appears in the target's inbox.
 *   transferred_from_device_id — the sender, kept for display ("from Main
 *     POS") and survives the claim (the target keeps who handed it over).
 *   transferred_at             — when the transfer was sent (inbox ordering).
 *
 * No new status value: a transferred order is still `held` (it upserts through
 * the exact order.hold path), which keeps it out of every revenue query by
 * construction and lets order.pay finalise it normally once claimed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->foreignId('transferred_to_device_id')
                ->nullable()
                ->after('device_id')
                ->constrained('pos_devices')
                ->nullOnDelete();
            $table->foreignId('transferred_from_device_id')
                ->nullable()
                ->after('transferred_to_device_id')
                ->constrained('pos_devices')
                ->nullOnDelete();
            $table->timestamp('transferred_at')->nullable()->after('transferred_from_device_id');

            // The target's inbox query filters on (branch, target, status);
            // index the addressed-device lookup so it stays cheap.
            $table->index(
                ['branch_id', 'transferred_to_device_id'],
                'pos_orders_branch_transfer_target_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropIndex('pos_orders_branch_transfer_target_idx');
            $table->dropConstrainedForeignId('transferred_from_device_id');
            $table->dropConstrainedForeignId('transferred_to_device_id');
            $table->dropColumn('transferred_at');
        });
    }
};
