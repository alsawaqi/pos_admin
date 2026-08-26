<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QR-session provenance and server-owned SoftPOS charge-claim state.
 *
 * All columns are nullable so this migration is inert for historical orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->foreignId('qr_session_id')
                ->nullable()
                ->constrained('pos_qr_sessions')
                ->nullOnDelete();
            $table->foreignId('charge_device_id')
                ->nullable()
                ->constrained('pos_devices')
                ->nullOnDelete();
            $table->unsignedInteger('charge_amount_baisas')->nullable();
            $table->timestamp('charge_claimed_at')->nullable();
            $table->timestamp('charge_deadline_at')->nullable();
            $table->string('charge_outcome', 16)->nullable();

            $table->index(
                ['status', 'charge_deadline_at'],
                'pos_orders_status_charge_deadline_idx',
            );
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX pos_orders_qr_session_live_unique
                 ON pos_orders (qr_session_id)
                 WHERE qr_session_id IS NOT NULL
                   AND status NOT IN ('paid', 'pending_verification', 'void', 'refunded')"
            );
            DB::statement(
                'CREATE INDEX pos_orders_qr_session_idx
                 ON pos_orders (qr_session_id)
                 WHERE qr_session_id IS NOT NULL'
            );
        } else {
            // No portable partial-index syntax. The later claim path also
            // serialises on the session row; retain a lookup index here.
            Schema::table('pos_orders', function (Blueprint $table): void {
                $table->index('qr_session_id', 'pos_orders_qr_session_idx');
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            // Raw indexes must go first: SQLite rebuilds the table when
            // dropping columns and cannot leave either index dangling.
            DB::statement('DROP INDEX IF EXISTS pos_orders_qr_session_live_unique');
            DB::statement('DROP INDEX IF EXISTS pos_orders_qr_session_idx');
        } else {
            Schema::table('pos_orders', function (Blueprint $table): void {
                $table->dropIndex('pos_orders_qr_session_idx');
            });
        }

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropIndex('pos_orders_status_charge_deadline_idx');
            $table->dropForeign(['qr_session_id']);
            $table->dropForeign(['charge_device_id']);
            $table->dropColumn([
                'qr_session_id',
                'charge_device_id',
                'charge_amount_baisas',
                'charge_claimed_at',
                'charge_deadline_at',
                'charge_outcome',
            ]);
        });
    }
};
