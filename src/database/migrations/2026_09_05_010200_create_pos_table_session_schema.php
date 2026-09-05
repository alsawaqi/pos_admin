<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seating schema owned by pos_admin, written by pos_api from T3 and read by
 * devices from T4/T5. Nothing consumes these tables or columns in T2.
 *
 * MySQL's plain fallback indexes enforce neither partial uniqueness rule;
 * every future open/merge path must hold the table row lock.
 *
 * table_id is required for one live seating per table. Admin soft-deletes
 * tables; a hard delete is explicit cleanup of their children. Money stays
 * on pos_orders, which is not cascaded by deleting a seating. A seating must
 * outlive its opening device, so device references use SET NULL.
 *
 * Scan identity stores hashes only, never raw fingerprints or IP addresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_table_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('pos_tables')->cascadeOnDelete();
            // open | billing | closing | closed | merged | expired (T3 enum)
            $table->string('status', 16)->default('open');
            // staff_till | staff_handheld | station | table_card
            $table->string('origin', 24);
            // T3: killing a payment station must leave the seating alive.
            $table->foreignId('opened_by_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->foreignId('closed_by_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            // The live bill is NULL until the first round / staff line lands.
            $table->foreignId('order_id')->nullable()->constrained('pos_orders')->nullOnDelete();
            // The winner this seating was folded into; NULL unless merged.
            $table->foreignId('merged_into_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            // T-MMDD-NNN from T1's pos_temp_reference_sequences, allocated in T3.
            $table->string('temp_reference', 32)->nullable();
            // T4 table.session.open replay key; NULL for server-originated opens.
            $table->string('client_request_id', 64)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('expires_at'); // T3 sets opened_at + 6 hours.
            $table->timestamp('billing_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // paid | staff_close | cleared | merged | expired (T3)
            $table->string('close_reason', 24)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status'], 'pos_table_sessions_branch_status_idx');
            $table->index(['branch_id', 'temp_reference'], 'pos_table_sessions_branch_temp_reference_idx');
            $table->index(['order_id'], 'pos_table_sessions_order_idx');
            $table->index(['status', 'expires_at'], 'pos_table_sessions_status_expires_idx');
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX pos_table_sessions_table_live_unique
                 ON pos_table_sessions (table_id)
                 WHERE status IN ('open', 'billing', 'closing')"
            );
            DB::statement(
                'CREATE UNIQUE INDEX pos_table_sessions_branch_request_unique
                 ON pos_table_sessions (branch_id, client_request_id)
                 WHERE client_request_id IS NOT NULL'
            );
        } else {
            // MySQL has no portable partial-index equivalent, so these lookup
            // indexes do NOT enforce uniqueness. Every future open/merge path
            // must retain its transactional guard on the table row.
            Schema::table('pos_table_sessions', function (Blueprint $table): void {
                $table->index(
                    ['table_id', 'status'],
                    'pos_table_sessions_table_status_idx',
                );
                $table->index(
                    ['branch_id', 'client_request_id'],
                    'pos_table_sessions_branch_request_idx',
                );
            });
        }

        Schema::create('pos_table_session_events', function (Blueprint $table): void {
            $table->id(); // The append-only feed cursor: devices poll ?after=<id>.
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('table_session_id')->constrained('pos_table_sessions')->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('pos_tables')->cascadeOnDelete();
            // opened | round_appended | moved | joined | billing | closed | merged |
            // expired | needs_review | customer_order_arrived | sent_to_counter (T4)
            $table->string('event_type', 32);
            $table->foreignId('device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at'); // No updated_at: append-only journal.
            $table->index(['branch_id', 'id'], 'pos_table_session_events_branch_cursor_idx');
            $table->index(['table_session_id', 'id'], 'pos_table_session_events_session_idx');
        });

        Schema::create('pos_qr_session_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('pos_tables')->nullOnDelete();
            $table->foreignId('qr_session_id')->nullable()->constrained('pos_qr_sessions')->nullOnDelete();
            $table->foreignId('table_session_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            // owner | viewer | refused: first scanner owns, second is read-only.
            $table->string('role', 16);
            $table->string('device_fingerprint_hash', 64)->nullable(); // SHA-256 only.
            $table->string('ip_hash', 64)->nullable(); // SHA-256 only.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // inside | outside | unknown | refused (T9)
            $table->string('geofence_verdict', 16)->nullable();
            $table->timestamp('scanned_at');
            $table->timestamp('created_at');
            $table->index(['branch_id', 'scanned_at'], 'pos_qr_session_scans_branch_scanned_idx');
            $table->index(['table_id', 'scanned_at'], 'pos_qr_session_scans_table_scanned_idx');
        });

        Schema::create('pos_kitchen_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            // e.g. round:<round id>; T4 defines the opaque key format.
            $table->string('ticket_key', 96);
            $table->foreignId('round_id')->nullable()->constrained('pos_qr_order_rounds')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('pos_orders')->nullOnDelete();
            $table->foreignId('claimed_by_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->timestamp('claimed_at');
            $table->timestamp('printed_at')->nullable();
            // printed | failed: the result, never the attempt (R7).
            $table->string('print_result', 16)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'ticket_key'], 'pos_kitchen_tickets_branch_key_unique');
        });

        // Adding constrained FKs also rebuilds SQLite tables. Drop partial
        // indexes first: recreating them only afterward is too late when legal
        // terminal rows collide in Laravel's intermediate FULL unique index.
        $this->dropExistingPartialIndexesForSqlite();

        Schema::table('pos_qr_sessions', function (Blueprint $table): void {
            $table->foreignId('table_session_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            $table->string('origin', 24)->nullable(); // station | table_card (T9)
            $table->string('scan_fingerprint_hash', 64)->nullable();
            $table->string('scan_ip_hash', 64)->nullable();
            $table->string('scan_geofence_verdict', 16)->nullable();
            $table->index(['table_session_id'], 'pos_qr_sessions_table_session_idx');
        });
        Schema::table('pos_qr_order_rounds', function (Blueprint $table): void {
            $table->foreignId('table_session_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            // Seating folded in FROM on a merge; NULL for a normal round.
            $table->foreignId('origin_table_session_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            $table->timestamp('kitchen_printed_at')->nullable();
            $table->boolean('needs_review')->default(false);
            // NULL seating ids leave existing rows unaffected. The shipped
            // pos_qr_rounds_session_request_unique remains unchanged.
            $table->unique(['table_session_id', 'client_request_id'], 'pos_qr_rounds_table_session_request_unique');
        });
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->foreignId('table_session_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            $table->index(['table_session_id'], 'pos_orders_table_session_idx');
        });
        Schema::table('pos_order_items', function (Blueprint $table): void {
            // prepared | not_prepared (T11); no wastage arithmetic in T2.
            $table->string('cancel_disposition', 16)->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });

        $this->restoreExistingPartialIndexesForSqlite();
    }

    public function down(): void
    {
        $this->dropExistingPartialIndexesForSqlite();

        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->dropColumn(['cancel_disposition', 'cancelled_at']);
        });
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropIndex('pos_orders_table_session_idx');
            $table->dropForeign(['table_session_id']);
            $table->dropColumn('table_session_id');
        });
        Schema::table('pos_qr_order_rounds', function (Blueprint $table): void {
            $table->dropUnique('pos_qr_rounds_table_session_request_unique');
            $table->dropForeign(['table_session_id']);
            $table->dropForeign(['origin_table_session_id']);
            $table->dropColumn(['table_session_id', 'origin_table_session_id', 'kitchen_printed_at', 'needs_review']);
        });
        Schema::table('pos_qr_sessions', function (Blueprint $table): void {
            $table->dropIndex('pos_qr_sessions_table_session_idx');
            $table->dropForeign(['table_session_id']);
            $table->dropColumn(['table_session_id', 'origin', 'scan_fingerprint_hash', 'scan_ip_hash', 'scan_geofence_verdict']);
        });

        $this->restoreExistingPartialIndexesForSqlite();

        Schema::dropIfExists('pos_kitchen_tickets');
        Schema::dropIfExists('pos_qr_session_scans');
        Schema::dropIfExists('pos_table_session_events');

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS pos_table_sessions_table_live_unique');
            DB::statement('DROP INDEX IF EXISTS pos_table_sessions_branch_request_unique');
        } else {
            Schema::table('pos_table_sessions', function (Blueprint $table): void {
                $table->dropIndex('pos_table_sessions_table_status_idx');
                $table->dropIndex('pos_table_sessions_branch_request_idx');
            });
        }

        Schema::dropIfExists('pos_table_sessions');
    }

    private function dropExistingPartialIndexesForSqlite(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS pos_qr_sessions_table_live_unique');
        DB::statement('DROP INDEX IF EXISTS pos_orders_qr_session_live_unique');
        DB::statement('DROP INDEX IF EXISTS pos_orders_qr_session_idx');
    }

    private function restoreExistingPartialIndexesForSqlite(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement(
            "CREATE UNIQUE INDEX pos_qr_sessions_table_live_unique
             ON pos_qr_sessions (table_id)
             WHERE table_id IS NOT NULL
               AND status IN ('pending', 'active', 'ordered')"
        );
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
    }
};
