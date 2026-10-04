<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 data contract — shift close (owner decisions 3 and 4) and
 * pay-outs from the drawer.
 *
 * pos_shifts
 *   closed_by_staff_id  who closed the drawer (the next cashier at a handover)
 *   close_device_id     the device the close came from
 *   needs_review        a sale reached the server after the close, or a sale
 *                       of the shift is in permanent sync failure
 *   late_sales_baisas   cash paid inside the shift's window that reached the
 *                       server after the close (the printed Z is not changed)
 *   payouts_baisas      device pay-outs (pos_expenses.paid_from_drawer)
 *                       inside the shift; they lower expected cash
 *
 * pos_expenses
 *   paid_from_drawer    true = cash taken out of a device drawer (a pay-out)
 *
 * Existing rows keep today's meaning: not reviewed, no late sales, no
 * pay-outs. On Postgres closed_by_staff_id / close_device_id reference
 * pos_staff / pos_devices (emptied if those rows are hard-deleted); SQLite
 * (the test mirror) gets the plain columns, since adding a foreign key there
 * rebuilds the table. pos:check-tenant-integrity checks closed_by_staff_id
 * belongs to the shift's company. pos_api and pos_merchant mirror the columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->unsignedBigInteger('closed_by_staff_id')->nullable();
            $table->unsignedBigInteger('close_device_id')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->bigInteger('late_sales_baisas')->default(0);
            $table->bigInteger('payouts_baisas')->default(0);
        });

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('pos_shifts', function (Blueprint $table): void {
                $table->foreign('closed_by_staff_id')->references('id')->on('pos_staff')->nullOnDelete();
                $table->foreign('close_device_id')->references('id')->on('pos_devices')->nullOnDelete();
            });
        }

        Schema::table('pos_expenses', function (Blueprint $table): void {
            $table->boolean('paid_from_drawer')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pos_expenses', function (Blueprint $table): void {
            $table->dropColumn('paid_from_drawer');
        });

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('pos_shifts', function (Blueprint $table): void {
                $table->dropForeign(['closed_by_staff_id']);
                $table->dropForeign(['close_device_id']);
            });
        }

        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->dropColumn(['closed_by_staff_id', 'close_device_id', 'needs_review', 'late_sales_baisas', 'payouts_baisas']);
        });
    }
};
