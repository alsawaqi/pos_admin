<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A (Additions doc §2) — the ingredient unit-of-measure
 * PIECE model + purchase batches + day-end stock counts.
 *
 * The Additions companion document locks one authoritative
 * model: every ingredient quantity in the database stays in the
 * ingredient's PRIMARY unit (pos_ingredients.unit — unchanged),
 * and where staff physically handle whole pieces (bottles,
 * crates, bags) the system converts at the entry boundary via
 * a per-ingredient ratio. Three additions:
 *
 * 1. pos_ingredients gains the piece fields (§2.3):
 *      piece_unit_label     — human label of the countable unit
 *                             ("bottle", "crate"). NULL = the
 *                             ingredient is not piece-tracked
 *                             (counted directly in its unit).
 *      units_per_piece      — primary units in ONE piece. For
 *                             fixed-ratio items (milk: 1 L per
 *                             bottle) it is static; for loose
 *                             items (tomato crate) every purchase
 *                             batch rewrites it (LAST BATCH WINS,
 *                             history preserved on the purchase
 *                             row). decimal(14,4) mirrors
 *                             pos_ingredient_units.factor.
 *      allow_fractional_pieces — UX flag: 0.4 bottles of milk is
 *                             meaningful, 4.7 eggs is not.
 *    The existing pos_ingredient_units alt-units stay as-is —
 *    they are generic entry conversions; the piece unit is the
 *    SPECIAL one used by purchases, transfers and day-end counts.
 *
 * 2. pos_ingredient_purchases (§2.4) — one row per purchase
 *    BATCH: pieces received, total paid, the units actually
 *    received (entered weight for loose produce), and the batch
 *    ratio frozen at purchase time. The matching inflow still
 *    goes through pos_stock_movements (type=restock,
 *    stock_movement_id links them); this table is the costing /
 *    supplier-history record the movement row can't hold.
 *    unit_cost is decimal(12,6) — NOT the money convention
 *    (12,3) — because a per-gram cost is routinely < 0.001 OMR;
 *    money totals (total_paid) stay (12,3).
 *
 * 3. pos_stock_counts + pos_stock_count_lines (§2.8) — the
 *    day-end reconciliation. Staff count closing stock in
 *    PIECES; the system converts to primary units, compares to
 *    the running balance (= expected: opening + purchases −
 *    consumption ± transfers), and writes the variance as a
 *    waste (shortfall) or adjustment (overage) movement. The
 *    header/lines rows are the queryable record feeding the
 *    Inventory Consumption report's counted/variance columns;
 *    counts can be submitted by a portal user OR by POS staff
 *    from the device (hence both recorded_by columns).
 *
 * Tenancy: company_id denormalised on both new tables, mirroring
 * pos_branch_stock / pos_ingredient_units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->string('piece_unit_label', 32)->nullable()->after('unit');
            $table->string('piece_unit_label_ar', 32)->nullable()->after('piece_unit_label');
            $table->decimal('units_per_piece', 14, 4)->nullable()->after('piece_unit_label_ar');
            $table->boolean('allow_fractional_pieces')->default(true)->after('units_per_piece');
        });

        Schema::create('pos_ingredient_purchases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('pos_suppliers')
                ->nullOnDelete();
            // NULL = the merchant entered base units directly
            // (no piece tracking on this purchase).
            $table->decimal('pieces_received', 12, 3)->nullable();
            // Total PRIMARY units that entered stock: pieces ×
            // ratio for fixed items, the weighed amount for loose.
            $table->decimal('units_received', 12, 3);
            $table->decimal('total_paid', 12, 3)->default(0);
            // total_paid / units_received — see docblock for the
            // (12,6) precision rationale.
            $table->decimal('unit_cost', 12, 6)->default(0);
            // The ratio THIS batch implied (units_received /
            // pieces_received). NULL when pieces weren't entered.
            $table->decimal('units_per_piece_at_purchase', 14, 4)->nullable();
            // TRUE when the merchant weighed the batch (loose
            // produce) rather than relying on the fixed ratio.
            $table->boolean('is_loose')->default(false);
            $table->foreignId('stock_movement_id')
                ->nullable()
                ->constrained('pos_stock_movements')
                ->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['company_id', 'ingredient_id', 'occurred_at'], 'pos_ingredient_purchases_company_ingredient_idx');
            $table->index(['branch_id', 'occurred_at'], 'pos_ingredient_purchases_branch_occurred_idx');
        });

        Schema::create('pos_stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            $table->text('note')->nullable();
            // Portal submission sets the user; device submission
            // sets the POS staff. Exactly one per row.
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->foreignId('recorded_by_pos_staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->nullOnDelete();
            $table->timestamp('counted_at')->useCurrent();
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'counted_at'], 'pos_stock_counts_company_branch_counted_idx');
        });

        Schema::create('pos_stock_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_count_id')
                ->constrained('pos_stock_counts')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();
            // What the staff physically counted. NULL when the
            // ingredient isn't piece-tracked and the count was
            // entered straight in primary units.
            $table->decimal('counted_pieces', 12, 3)->nullable();
            $table->decimal('counted_units', 12, 3);
            // The running balance at count time — frozen here so
            // the line stays meaningful after later movements.
            $table->decimal('expected_units', 12, 3);
            // counted − expected. Negative = shortfall (waste),
            // positive = overage (adjustment-up).
            $table->decimal('variance_units', 12, 3);
            $table->decimal('unit_cost_at_time', 12, 3)->default(0);
            // The variance movement written for this line; NULL
            // when variance was zero (no movement needed).
            $table->foreignId('stock_movement_id')
                ->nullable()
                ->constrained('pos_stock_movements')
                ->nullOnDelete();
            $table->unique(['stock_count_id', 'ingredient_id'], 'pos_stock_count_lines_count_ingredient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_stock_count_lines');
        Schema::dropIfExists('pos_stock_counts');
        Schema::dropIfExists('pos_ingredient_purchases');
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->dropColumn([
                'piece_unit_label',
                'piece_unit_label_ar',
                'units_per_piece',
                'allow_fractional_pieces',
            ]);
        });
    }
};
