<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 fix order 1 (F7) — late pay-outs.
 *
 * pos_shifts.late_payouts_baisas is the cash paid out of a drawer during the
 * shift whose pay-out reached the server after the shift was closed (the
 * counterpart of late_sales_baisas; the printed Z is not changed). pos_api
 * adds to it, sets needs_review and writes a note line. The corrected
 * expected cash = expected + late sales - late pay-outs. A close (after a
 * portal re-open) counts every pay-out again and sets it back to 0.
 *
 * Existing shifts start at 0. On Postgres a CHECK keeps it >= 0; SQLite (the
 * test mirror) enforces nothing. pos_api and pos_merchant mirror the column.
 */
return new class extends Migration
{
    private const CHECK = 'pos_shifts_late_payouts_baisas_check';

    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->bigInteger('late_payouts_baisas')->default(0);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_shifts" ADD CONSTRAINT "'.self::CHECK.'" CHECK ("late_payouts_baisas" >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_shifts" DROP CONSTRAINT IF EXISTS "'.self::CHECK.'"');
        }

        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->dropColumn('late_payouts_baisas');
        });
    }
};
