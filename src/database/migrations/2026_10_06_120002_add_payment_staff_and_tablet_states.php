<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P6 fix order 1.
 *
 * F-1 — pos_payments.staff_id: the staff member who took the payment
 * (order.pay already carries staff_id). A QR or customer-tablet payment taken
 * on a device that has no shift of its own then belongs to the shared shift
 * of that staff member (the Z report of the till where the shift was opened).
 * NULL for every existing payment and for an unknown payer.
 *
 * F-2 — pos_tablet_orders.redeem_status may be `superseded` (an approved
 * points redemption whose bill slot was later cleared: a staff clear, a
 * customer change, a line-cancel clamp or a void).
 *
 * F-2 / F-6 / F-8 — the tablet audit gains `redeem_superseded`,
 * `round_rejected` (a dine-in tablet round rejected from the table screens)
 * and `edited` (staff changed the lines before sending).
 *
 * pos:check-tenant-integrity reports a payment whose staff member belongs to
 * another merchant than its order.
 */
return new class extends Migration
{
    private const REDEEM_CHECK = 'pos_tablet_orders_redeem_status_check';

    private const EVENT_CHECK = 'pos_tablet_order_events_type_check';

    private const OLD_EVENTS = ['submitted', 'taken', 'taken_over', 'sent_to_kitchen', 'redeem_approved', 'redeem_rejected'];

    private const NEW_EVENTS = ['redeem_superseded', 'round_rejected', 'edited'];

    public function up(): void
    {
        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->foreignId('staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->index(['staff_id', 'captured_at'], 'pos_payments_staff_captured_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_tablet_orders" DROP CONSTRAINT IF EXISTS "'.self::REDEEM_CHECK.'"');
            DB::statement('ALTER TABLE "pos_tablet_orders" ADD CONSTRAINT "'.self::REDEEM_CHECK.'" CHECK ("redeem_status" IS NULL OR "redeem_status" IN (\'requested\', \'approved\', \'rejected\', \'superseded\'))');
            DB::statement('ALTER TABLE "pos_tablet_order_events" DROP CONSTRAINT IF EXISTS "'.self::EVENT_CHECK.'"');
            DB::statement('ALTER TABLE "pos_tablet_order_events" ADD CONSTRAINT "'.self::EVENT_CHECK.'" CHECK ("event_type" IN ('
                .self::quoted([...self::OLD_EVENTS, ...self::NEW_EVENTS]).'))');
        }
    }

    public function down(): void
    {
        // Who took a payment, and the new tablet states, are history: refuse
        // to drop them once used.
        if (Schema::hasColumn('pos_payments', 'staff_id') && DB::table('pos_payments')->whereNotNull('staff_id')->exists()) {
            throw new RuntimeException('Cannot roll back 2026_10_06_120002: payments record the staff member who took them.');
        }
        if (DB::table('pos_tablet_orders')->where('redeem_status', 'superseded')->exists()
            || DB::table('pos_tablet_order_events')->whereIn('event_type', self::NEW_EVENTS)->exists()) {
            throw new RuntimeException('Cannot roll back 2026_10_06_120002: tablet orders use the new states.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_tablet_order_events" DROP CONSTRAINT IF EXISTS "'.self::EVENT_CHECK.'"');
            DB::statement('ALTER TABLE "pos_tablet_order_events" ADD CONSTRAINT "'.self::EVENT_CHECK.'" CHECK ("event_type" IN ('
                .self::quoted(self::OLD_EVENTS).'))');
            DB::statement('ALTER TABLE "pos_tablet_orders" DROP CONSTRAINT IF EXISTS "'.self::REDEEM_CHECK.'"');
            DB::statement('ALTER TABLE "pos_tablet_orders" ADD CONSTRAINT "'.self::REDEEM_CHECK.'" CHECK ("redeem_status" IS NULL OR "redeem_status" IN (\'requested\', \'approved\', \'rejected\'))');
        }

        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->dropIndex('pos_payments_staff_captured_idx');
            $table->dropConstrainedForeignId('staff_id');
        });
    }

    /** @param list<string> $values */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'".$v."'", $values));
    }
};
