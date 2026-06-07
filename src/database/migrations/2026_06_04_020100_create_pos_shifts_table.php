<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7a — POS shifts (blueprint §10.8).
 *
 * One row per cashier-shift on a device. opened_at captures
 * the opening float (the cash the cashier starts the day with);
 * closed_at captures the closing count + the system's expected
 * close + the variance.
 *
 * §5.11.10 Staff Activity Report aggregates orders per shift;
 * §10.9 Sync & Audit ties shifts to the audit log; Phase 8
 * device endpoints /api/v1/device/shifts/open + .../close are
 * the write path.
 *
 * variance = closing_cash - expected_cash. Negative variance
 * (cashier is SHORT) is the audit-trigger case the merchant
 * follows up on.
 *
 * status: open / closed. The (branch_id, device_id, status=open)
 * combination should be unique at any moment — a device can't
 * have two open shifts at once. We don't enforce that as a DB
 * unique constraint because the Phase 8 ShiftAction will check
 * + reject; a constraint would block the legitimate edge case
 * where an old crashed shift gets force-closed by an admin
 * concurrently with the cashier reopening.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // company_id denormalised from branch for tenant-
            // scoped report queries (same rationale as restock
            // requests / ledger entries).
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            $table->foreignId('device_id')
                ->nullable()
                ->constrained('pos_devices')
                ->nullOnDelete();
            $table->foreignId('staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->nullOnDelete();

            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();

            // ---- Cash counts ----
            $table->decimal('opening_cash', 12, 3)->default(0);
            // NULL until close.
            $table->decimal('closing_cash', 12, 3)->nullable();
            // expected_cash = opening_cash + SUM(cash payments
            // during shift) - SUM(cash change_given). The Phase
            // 8 ShiftAction computes this from the orders linked
            // to this shift at close time.
            $table->decimal('expected_cash', 12, 3)->nullable();
            // closing_cash - expected_cash. NULL until close.
            $table->decimal('variance', 12, 3)->nullable();

            // 32-char string. App enum
            // {@see App\Enums\ShiftStatus}: open / closed.
            $table->string('status', 32)->default('open');

            $table->text('note')->nullable();

            $table->timestamps();

            // Hot paths
            $table->index(['company_id', 'opened_at'], 'pos_shifts_company_opened_idx');
            $table->index(['branch_id', 'status'], 'pos_shifts_branch_status_idx');
            $table->index(['staff_id', 'opened_at'], 'pos_shifts_staff_opened_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_shifts');
    }
};
