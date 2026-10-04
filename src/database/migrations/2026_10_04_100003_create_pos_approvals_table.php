<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 data contract — who approved what (owner decision 2).
 *
 * pos_api writes one row per authorization it checks: each block a device
 * sends with a gated action (a sync event or an online call), each gated
 * action a P5 build sent without a block (result `missing`), each event of an
 * old build where a check would apply (result `legacy`), and each of the six
 * online manager-PIN actions. Rows are never updated by devices.
 *
 *   action        a permission key (order.void_paid, discount.manual, comp,
 *                 ...), or card.reverse, production.cancel, disposition,
 *                 qr.payment_review, qr.expired_cancel, bill.combine
 *   subject_type  order | shift | expense | product | table_session |
 *                 payment | production | ... ; subject_uuid the entity
 *   amount        OMR (decimal 12,3) when the action has one
 *   ref           which discount / comp line of the event ("discount:0")
 *   mode          position (the actor's own tick) | approval (an approver PIN)
 *   method        offline (proof made on the device) | online | NULL
 *   approved_at   the device's time of the approval (millisecond precision)
 *   verified_at   when the server checked it
 *   result        position_ok | verified | failed | missing | unverifiable |
 *                 legacy
 *
 * Paid sales are never rejected because of a row here; the report reads it.
 * On Postgres CHECK constraints back the three enumerations; SQLite (the test
 * mirror) enforces nothing. pos:check-tenant-integrity checks that the
 * branch, actor and approver belong to the row's company. pos_api and
 * pos_merchant mirror the table in their test schemas.
 */
return new class extends Migration
{
    public const RESULTS = ['position_ok', 'verified', 'failed', 'missing', 'unverifiable', 'legacy'];

    public function up(): void
    {
        Schema::create('pos_approvals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->string('client_event_id', 64)->nullable();
            $table->string('action', 32);
            $table->string('subject_type', 32);
            $table->string('subject_uuid', 64)->nullable();
            $table->decimal('amount', 12, 3)->nullable();
            $table->string('ref', 64)->nullable();
            $table->foreignId('actor_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->foreignId('approver_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->string('mode', 16);
            $table->string('method', 16)->nullable();
            $table->timestamp('approved_at', 3)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('result', 16);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['company_id', 'branch_id', 'approved_at'], 'pos_approvals_company_branch_approved_idx');
            $table->index(['approver_staff_id', 'approved_at'], 'pos_approvals_approver_approved_idx');
            $table->index(['company_id', 'result'], 'pos_approvals_company_result_idx');
            $table->index(['device_id', 'client_event_id'], 'pos_approvals_device_event_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $results = "'".implode("', '", self::RESULTS)."'";
            DB::statement('ALTER TABLE "pos_approvals" ADD CONSTRAINT "pos_approvals_result_check" CHECK ("result" IN ('.$results.'))');
            DB::statement('ALTER TABLE "pos_approvals" ADD CONSTRAINT "pos_approvals_mode_check" CHECK ("mode" IN (\'position\', \'approval\'))');
            DB::statement('ALTER TABLE "pos_approvals" ADD CONSTRAINT "pos_approvals_method_check" CHECK ("method" IS NULL OR "method" IN (\'offline\', \'online\'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_approvals');
    }
};
