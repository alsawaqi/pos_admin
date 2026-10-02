<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P2 P2-6 — a fair count variance.
 *
 * The variance compares the counted quantity with the book balance AT THE
 * COUNT MOMENT (pos_stock_counts.counted_at), read from the ledger's
 * occurred_at (sale time for device sales, not sync time). A sale made before
 * the count that reaches the server only AFTER the count was submitted is
 * folded into that count when it arrives: the line's expected/variance are
 * recomputed, a `count_correction` movement dated at the count keeps the
 * balance after the count equal to what was counted, and the line's
 * reconciliation waste follows the fair shortfall.
 *
 *   late_movement_units  signed sum of such late pre-count movements already
 *                        folded into this line (0 for most lines);
 *                        expected_units − late_movement_units is the balance
 *                        the count saw when it was submitted.
 *   waste_record_id      the line's reconciliation_variance waste record, so a
 *                        later fold can resize (or create) it. NULL on lines
 *                        without a shortfall and on pre-P2 lines (those are
 *                        found through stock_movement_id).
 *
 * pos_merchant and pos_api mirror the columns in their test schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_stock_count_lines', function (Blueprint $table): void {
            $table->decimal('late_movement_units', 14, 4)->default(0);
            $table->foreignId('waste_record_id')
                ->nullable()
                ->constrained('pos_waste_records')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_stock_count_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('waste_record_id');
            $table->dropColumn('late_movement_units');
        });
    }
};
