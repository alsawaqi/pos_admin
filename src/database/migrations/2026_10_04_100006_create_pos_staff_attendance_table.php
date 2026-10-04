<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 data contract — clock in / clock out (owner decision 6).
 *
 * One row per worked period. A device writes it through the sync events
 * staff.clock_in / staff.clock_out (source = device, idempotent by uuid); the
 * portal's Hours report edits it (edited_by_user_id + edit_reason, audited;
 * source = portal for a row the portal adds).
 *
 *   clock_out_at NULL  the person is still clocked in
 *   flags              JSON, e.g. {"no_clock_out": true} for an open row older
 *                      than 16 h, {"no_clock_in": true} for a clock-out that
 *                      found nothing open
 *
 * Tenant integrity: on Postgres composite foreign keys tie (staff_id,
 * company_id) to pos_staff and (branch_id, company_id) to pos_branches (the
 * UNIQUE (id, company_id) indexes of 2026_10_04_100002), so a row for another
 * company's staff or branch is rejected; a CHECK backs `source`. SQLite (the
 * test mirror) has the plain foreign keys only; pos:check-tenant-integrity
 * checks the same (attendance_company). pos_api and pos_merchant mirror it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_staff_attendance', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('pos_staff')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->string('source', 16)->default('device');
            $table->foreignId('edited_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->text('edit_reason')->nullable();
            $table->json('flags')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'staff_id', 'clock_in_at'], 'pos_staff_attendance_company_staff_in_idx');
            $table->index(['staff_id', 'clock_out_at'], 'pos_staff_attendance_staff_open_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_staff_attendance" ADD CONSTRAINT "pos_staff_attendance_source_check" CHECK ("source" IN (\'device\', \'portal\'))');
            DB::statement('ALTER TABLE "pos_staff_attendance" ADD CONSTRAINT "pos_staff_attendance_staff_company_foreign"
                FOREIGN KEY ("staff_id", "company_id") REFERENCES "pos_staff" ("id", "company_id") ON DELETE CASCADE');
            DB::statement('ALTER TABLE "pos_staff_attendance" ADD CONSTRAINT "pos_staff_attendance_branch_company_foreign"
                FOREIGN KEY ("branch_id", "company_id") REFERENCES "pos_branches" ("id", "company_id") ON DELETE CASCADE');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_staff_attendance');
    }
};
