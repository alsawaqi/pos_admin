<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 fix order 1 (F6) — a pay-out belongs to a drawer.
 *
 * pos_expenses.shift_id is the drawer shift a device pay-out
 * (paid_from_drawer = true) was taken from: an `expense.log` pay-out carries
 * the device's open drawer shift as `shift_uuid` and pos_api stores its id.
 * The shift close takes those pay-outs off that shift's expected cash; a
 * pay-out without it (an old build, or a plain expense) keeps today's rule.
 * Existing rows stay NULL.
 *
 * On Postgres it references pos_shifts (emptied if the shift row is
 * hard-deleted); SQLite (the test mirror) gets the plain column, since
 * adding a foreign key there rebuilds the table. pos:check-tenant-integrity
 * checks the shift belongs to the expense's company. pos_api and
 * pos_merchant mirror the column.
 */
return new class extends Migration
{
    private const INDEX = 'pos_expenses_shift_id_index';

    public function up(): void
    {
        Schema::table('pos_expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->index('shift_id', self::INDEX);
        });

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('pos_expenses', function (Blueprint $table): void {
                $table->foreign('shift_id')->references('id')->on('pos_shifts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('pos_expenses', function (Blueprint $table): void {
                $table->dropForeign(['shift_id']);
            });
        }

        Schema::table('pos_expenses', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->dropColumn('shift_id');
        });
    }
};
