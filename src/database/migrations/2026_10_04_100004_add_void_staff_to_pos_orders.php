<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 data contract — who voided an order and who approved it (B1).
 *
 *   voided_by_staff_id         the staff member who performed the void
 *   void_approved_by_staff_id  the verified approver (or the voider when their
 *                              own position allowed it)
 *
 * Both are written by pos_api's order.void and by the card-reversal void
 * (pos_admin's reversal copy). Existing orders keep NULL. On Postgres both
 * reference pos_staff and empty if that staff row is ever hard-deleted.
 * SQLite (the test mirror) gets the plain columns: adding a foreign key there
 * rebuilds pos_orders and would drop its partial QR-session index.
 * pos:check-tenant-integrity checks both belong to the order's company.
 * pos_api and pos_merchant mirror the columns in their test schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('voided_by_staff_id')->nullable();
            $table->unsignedBigInteger('void_approved_by_staff_id')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('pos_orders', function (Blueprint $table): void {
                $table->foreign('voided_by_staff_id')->references('id')->on('pos_staff')->nullOnDelete();
                $table->foreign('void_approved_by_staff_id')->references('id')->on('pos_staff')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('pos_orders', function (Blueprint $table): void {
                $table->dropForeign(['voided_by_staff_id']);
                $table->dropForeign(['void_approved_by_staff_id']);
            });
        }

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn(['voided_by_staff_id', 'void_approved_by_staff_id']);
        });
    }
};
