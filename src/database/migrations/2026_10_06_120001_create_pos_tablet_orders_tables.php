<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P6 (customer tablet) — what a customer tablet order needs beyond the
 * order itself. pos_api writes both tables; pos_admin owns the schema.
 *
 * pos_tablet_orders — one row per tablet submit (a Quick / To go order, or a
 * dine-in round on a table bill):
 *   company_id, branch_id   the tablet's merchant and branch (cascade)
 *   device_id               the tablet (NULL once the device row is deleted)
 *   client_uuid             the tablet's submit key; (device_id, client_uuid)
 *                           is unique, so a repeated submit returns the first
 *   order_id                the order (Quick / To go) or the table bill
 *   round_id                dine in: the pending round on the bill; Quick /
 *                           To go: the kitchen round written when staff send it
 *   table_id                dine in only
 *   order_type              'dine_in' | 'quick' | 'to_go' (CHECK)
 *   payment_choice          'cash' | 'points' (CHECK)
 *   customer_id             the customer the typed phone resolved to
 *   ready_in_minutes        the largest cooking time of the lines (NULL: none)
 *   subtotal / tax / total  the server's price of this submit, in baisas
 *   kitchen_lines           Quick / To go: the frozen kitchen lines (the
 *                           round's priced_lines shape), written on send
 *   redeem_*                the customer's points request: status requested |
 *                           approved | rejected (CHECK), rule, blocks, and on
 *                           approval the units, amount and discount row; who
 *                           resolved it and when
 *   taken_by_*, taken_at    the staff member who took it ("Taken by <name>")
 *   sent_*                  who sent it to the kitchen and when
 *   submitted_at, timestamps
 *
 * pos_tablet_order_events — append-only audit (submitted, taken, taken_over,
 * sent_to_kitchen, redeem_approved, redeem_rejected), ids and outcomes only.
 *
 * pos:check-tenant-integrity checks that every reference belongs to the
 * row's own merchant (and branch for the order, table and device).
 */
return new class extends Migration
{
    private const TYPE_CHECK = 'pos_tablet_orders_order_type_check';

    private const PAYMENT_CHECK = 'pos_tablet_orders_payment_choice_check';

    private const REDEEM_CHECK = 'pos_tablet_orders_redeem_status_check';

    private const BLOCKS_CHECK = 'pos_tablet_orders_redeem_blocks_check';

    private const MONEY_CHECK = 'pos_tablet_orders_money_check';

    private const EVENT_CHECK = 'pos_tablet_order_events_type_check';

    public function up(): void
    {
        Schema::create('pos_tablet_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->string('client_uuid', 64);
            $table->foreignId('order_id')->constrained('pos_orders')->cascadeOnDelete();
            $table->foreignId('round_id')->nullable()->constrained('pos_qr_order_rounds')->nullOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('pos_tables')->nullOnDelete();
            $table->string('order_type', 16);
            $table->string('payment_choice', 8)->default('cash');
            $table->foreignId('customer_id')->nullable()->constrained('pos_customers')->nullOnDelete();
            $table->unsignedSmallInteger('ready_in_minutes')->nullable();
            $table->unsignedInteger('subtotal_baisas');
            $table->unsignedInteger('tax_baisas');
            $table->unsignedInteger('total_baisas');
            $table->json('kitchen_lines')->nullable();
            $table->string('redeem_status', 16)->nullable();
            $table->foreignId('redeem_rule_id')->nullable()->constrained('pos_loyalty_rules')->nullOnDelete();
            $table->unsignedSmallInteger('redeem_blocks')->nullable();
            $table->unsignedInteger('redeem_units')->nullable();
            $table->unsignedInteger('redeem_amount_baisas')->nullable();
            $table->foreignId('redeem_discount_row_id')->nullable()->constrained('pos_order_discounts')->nullOnDelete();
            $table->foreignId('redeem_resolved_by_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->foreignId('redeem_resolved_by_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->timestamp('redeem_resolved_at')->nullable();
            $table->foreignId('taken_by_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->foreignId('taken_by_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('sent_to_kitchen_at')->nullable();
            $table->foreignId('sent_by_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->foreignId('sent_by_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['device_id', 'client_uuid'], 'pos_tablet_orders_device_client_unique');
            $table->index(['branch_id', 'sent_to_kitchen_at'], 'pos_tablet_orders_branch_sent_idx');
            $table->index(['order_id'], 'pos_tablet_orders_order_idx');
            $table->index(['round_id'], 'pos_tablet_orders_round_idx');
            $table->index(['company_id', 'redeem_status'], 'pos_tablet_orders_company_redeem_idx');
        });

        Schema::create('pos_tablet_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('tablet_order_id')->constrained('pos_tablet_orders')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->foreignId('staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['tablet_order_id', 'id'], 'pos_tablet_order_events_order_idx');
            $table->index(['company_id', 'event_type', 'created_at'], 'pos_tablet_order_events_type_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_tablet_orders" ADD CONSTRAINT "'.self::TYPE_CHECK.'" CHECK ("order_type" IN (\'dine_in\', \'quick\', \'to_go\'))');
            DB::statement('ALTER TABLE "pos_tablet_orders" ADD CONSTRAINT "'.self::PAYMENT_CHECK.'" CHECK ("payment_choice" IN (\'cash\', \'points\'))');
            DB::statement('ALTER TABLE "pos_tablet_orders" ADD CONSTRAINT "'.self::REDEEM_CHECK.'" CHECK ("redeem_status" IS NULL OR "redeem_status" IN (\'requested\', \'approved\', \'rejected\'))');
            DB::statement('ALTER TABLE "pos_tablet_orders" ADD CONSTRAINT "'.self::BLOCKS_CHECK.'" CHECK (("redeem_status" IS NULL) = ("redeem_blocks" IS NULL) AND ("redeem_blocks" IS NULL OR "redeem_blocks" BETWEEN 1 AND 50))');
            DB::statement('ALTER TABLE "pos_tablet_orders" ADD CONSTRAINT "'.self::MONEY_CHECK.'" CHECK ("total_baisas" >= 0 AND "subtotal_baisas" >= 0 AND "tax_baisas" >= 0)');
            DB::statement('ALTER TABLE "pos_tablet_order_events" ADD CONSTRAINT "'.self::EVENT_CHECK.'" CHECK ("event_type" IN (\'submitted\', \'taken\', \'taken_over\', \'sent_to_kitchen\', \'redeem_approved\', \'redeem_rejected\'))');
        }
    }

    public function down(): void
    {
        // A tablet order's taker, kitchen time and points request are its only
        // record: refuse to drop them once a tablet has ordered.
        if (Schema::hasTable('pos_tablet_orders') && DB::table('pos_tablet_orders')->exists()) {
            throw new RuntimeException('Cannot roll back 2026_10_06_120001: customer tablet orders exist.');
        }

        Schema::dropIfExists('pos_tablet_order_events');
        Schema::dropIfExists('pos_tablet_orders');
    }
};
