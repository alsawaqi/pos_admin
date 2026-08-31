<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Private confirmation state and the durable acceptance-order cursor.
 *
 * The server freezes the complete append-ready inventory and discount
 * snapshot here at submission time, then replays it verbatim on confirmation.
 * It must never be emitted to a customer or device. NULL identifies a
 * kitchen-direct round or a staff-confirm round whose resolution is complete.
 *
 * Production PostgreSQL first acquires the fixed transaction advisory lock
 * pg_advisory_xact_lock(814200205), then allocates accepted_seq explicitly
 * with nextval('pos_qr_order_rounds_accepted_seq_seq'), and holds that lock
 * through commit. nextval alone orders allocation, not commit visibility: a
 * later value could otherwise become feed-visible before an earlier value.
 * The sequence plus commit serialization makes the cursor skip-safe. The
 * column deliberately has no default: pending, rejected, and pre-deployment
 * legacy rounds must remain NULL. Sequence gaps after rolled-back transactions
 * are harmless.
 *
 * The isolated SQLite test mirror has no database sequence. Its application
 * writer allocates MAX(accepted_seq) + 1 inside the same transaction. The
 * unique index rejects a rare colliding writer, and the standard
 * DB::transaction(..., 5) retry envelope reruns that allocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_qr_order_rounds', function (Blueprint $table): void {
            $table->json('confirm_payload')->nullable();
            $table->unsignedBigInteger('accepted_seq')->nullable();

            $table->unique(
                'accepted_seq',
                'pos_qr_order_rounds_accepted_seq_unique',
            );
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE SEQUENCE pos_qr_order_rounds_accepted_seq_seq');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE pos_qr_order_rounds_accepted_seq_seq');
        }

        Schema::table('pos_qr_order_rounds', function (Blueprint $table): void {
            $table->dropUnique('pos_qr_order_rounds_accepted_seq_unique');
            $table->dropColumn(['confirm_payload', 'accepted_seq']);
        });
    }
};
