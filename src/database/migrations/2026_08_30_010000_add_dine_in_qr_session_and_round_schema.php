<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable table-bound QR sessions and immutable, server-priced order rounds.
 *
 * Existing quick-order sessions remain distinguished by a NULL table_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_qr_sessions', function (Blueprint $table): void {
            $table->foreignId('table_id')
                ->nullable()
                ->constrained('pos_tables')
                ->nullOnDelete();
            $table->timestamp('secret_rotated_at')->nullable();
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX pos_qr_sessions_table_live_unique
                 ON pos_qr_sessions (table_id)
                 WHERE table_id IS NOT NULL
                   AND status IN ('pending', 'active', 'ordered')"
            );
        } else {
            // MySQL has no portable partial-index equivalent, so this lookup
            // index does NOT enforce one live session per table. The open-table
            // path must retain its transactional guard on these columns.
            Schema::table('pos_qr_sessions', function (Blueprint $table): void {
                $table->index(
                    ['table_id', 'status'],
                    'pos_qr_sessions_table_status_idx',
                );
            });
        }

        Schema::create('pos_qr_order_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qr_session_id')
                ->constrained('pos_qr_sessions')
                ->cascadeOnDelete();
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('pos_orders')
                ->nullOnDelete();
            $table->unsignedInteger('round_no');
            $table->string('status', 24);
            $table->string('client_request_id', 64);
            $table->json('priced_lines');
            $table->unsignedInteger('subtotal_baisas');
            $table->unsignedInteger('tax_baisas');
            $table->unsignedInteger('total_baisas');
            $table->timestamp('submitted_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_device_id')
                ->nullable()
                ->constrained('pos_devices')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['qr_session_id', 'client_request_id'],
                'pos_qr_rounds_session_request_unique',
            );
            $table->index(
                ['order_id', 'status'],
                'pos_qr_rounds_order_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_qr_order_rounds');

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            // Drop the raw partial index before SQLite rebuilds the table to
            // remove its columns.
            DB::statement('DROP INDEX IF EXISTS pos_qr_sessions_table_live_unique');
        } else {
            Schema::table('pos_qr_sessions', function (Blueprint $table): void {
                $table->dropIndex('pos_qr_sessions_table_status_idx');
            });
        }

        Schema::table('pos_qr_sessions', function (Blueprint $table): void {
            $table->dropForeign(['table_id']);
            $table->dropColumn(['table_id', 'secret_rotated_at']);
        });
    }
};
