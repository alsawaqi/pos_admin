<?php

declare(strict_types=1);

/*
 * LAUNCH-P2 schema (pos_admin owns every migration):
 *  - P2-1 ingredient quantities -> numeric(14,4), per-base-unit costs ->
 *         numeric(15,6); widen only, never narrow, idempotent;
 *  - P2-3 receipt lines remember the purchase unit, quantity and prices;
 *  - P2-6 count lines carry the late-movement fold and their waste record.
 *
 * The suites run on SQLite, which has no numeric precision; the Postgres
 * statements are pinned here as data and run for real in the live-copy
 * rehearsal (rehearse-p2.sh).
 */

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function p2WideningMigration(): object
{
    return require database_path('migrations/2026_10_02_000001_widen_ingredient_quantities_and_unit_costs.php');
}

/** The numeric types every P2 column has on a launch-p1 database. */
function p2LaunchP1Types(string $table, array $columns): array
{
    $types = [];
    foreach ($columns as $column) {
        $types[$column] = match ("{$table}.{$column}") {
            'pos_addons.ingredient_qty' => [10, 3],
            'pos_ingredient_purchases.unit_cost' => [12, 6],
            default => [12, 3],
        };
    }

    return $types;
}

it('widens every ingredient quantity to 4 and every per-unit cost to 6 decimals on a launch-p1 database', function (): void {
    $statements = p2WideningMigration()->statements(p2LaunchP1Types(...));

    expect($statements)->toBe([
        'ALTER TABLE "pos_ingredients" ALTER COLUMN "min_stock_threshold" TYPE numeric(14,4), ALTER COLUMN "default_unit_cost" TYPE numeric(15,6)',
        'ALTER TABLE "pos_branch_stock" ALTER COLUMN "quantity" TYPE numeric(14,4)',
        'ALTER TABLE "pos_ingredient_stock" ALTER COLUMN "quantity" TYPE numeric(14,4)',
        'ALTER TABLE "pos_stock_movements" ALTER COLUMN "quantity" TYPE numeric(14,4), ALTER COLUMN "unit_cost_at_time" TYPE numeric(15,6)',
        'ALTER TABLE "pos_product_recipes" ALTER COLUMN "quantity" TYPE numeric(14,4)',
        'ALTER TABLE "pos_addons" ALTER COLUMN "ingredient_qty" TYPE numeric(14,4)',
        'ALTER TABLE "pos_addon_consumptions" ALTER COLUMN "quantity" TYPE numeric(14,4)',
        'ALTER TABLE "pos_waste_records" ALTER COLUMN "quantity" TYPE numeric(14,4), ALTER COLUMN "unit_cost_at_time" TYPE numeric(15,6)',
        'ALTER TABLE "pos_restock_request_lines" ALTER COLUMN "quantity_requested" TYPE numeric(14,4), ALTER COLUMN "quantity_allocated" TYPE numeric(14,4)',
        'ALTER TABLE "pos_branch_transfer_lines" ALTER COLUMN "quantity" TYPE numeric(14,4), ALTER COLUMN "unit_cost_at_time" TYPE numeric(15,6)',
        'ALTER TABLE "pos_ingredient_purchases" ALTER COLUMN "pieces_received" TYPE numeric(14,4), ALTER COLUMN "units_received" TYPE numeric(14,4), ALTER COLUMN "unit_cost" TYPE numeric(15,6)',
        'ALTER TABLE "pos_stock_count_lines" ALTER COLUMN "counted_pieces" TYPE numeric(14,4), ALTER COLUMN "counted_units" TYPE numeric(14,4), ALTER COLUMN "expected_units" TYPE numeric(14,4), ALTER COLUMN "variance_units" TYPE numeric(14,4), ALTER COLUMN "unit_cost_at_time" TYPE numeric(15,6)',
        'ALTER TABLE "pos_production_lines" ALTER COLUMN "quantity" TYPE numeric(14,4)',
        'ALTER TABLE "pos_purchase_receipt_lines" ALTER COLUMN "quantity" TYPE numeric(14,4)',
        'ALTER TABLE "pos_product_stock_movements" ALTER COLUMN "unit_cost" TYPE numeric(15,6)',
    ]);
});

it('never narrows a column and changes nothing once applied', function (): void {
    $migration = p2WideningMigration();

    // Already applied: every column at the target type -> nothing to do.
    $applied = $migration->statements(function (string $table, array $columns) use ($migration): array {
        $types = [];
        foreach ($columns as $column) {
            $types[$column] = $migration->plan()[$table][$column];
        }

        return $types;
    });
    expect($applied)->toBe([]);

    // A column held wider in one dimension keeps it; only the other grows.
    expect($migration::widened([20, 2], 14, 4))->toBe([22, 4]);
    expect($migration::widened([12, 8], 15, 6))->toBe([17, 8]);
    expect($migration::widened([18, 6], 15, 6))->toBeNull();
    // Unconstrained numeric or a missing column: left alone.
    expect($migration::widened([null, null], 14, 4))->toBeNull();
    expect($migration::widened(null, 14, 4))->toBeNull();
    // Integer digits are never lost: (10,3) has 7, (12,3) has 9, both -> 10.
    expect($migration::widened([10, 3], 14, 4))->toBe([14, 4]);
    expect($migration::widened([12, 3], 15, 6))->toBe([15, 6]);
});

it('is a no-op on SQLite and keeps every stored value', function (): void {
    expect(DB::getDriverName())->toBe('sqlite');
    $ingredientId = DB::table('pos_ingredients')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'company_id' => Company::factory()->create()->id,
        'name' => 'Saffron',
        'unit' => 'g',
        'default_unit_cost' => '0.000350',
        'min_stock_threshold' => '0.3000',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::enableQueryLog();
    p2WideningMigration()->up();
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();

    $row = DB::table('pos_ingredients')->find($ingredientId);
    expect((float) $row->default_unit_cost)->toBe(0.00035)
        ->and((float) $row->min_stock_threshold)->toBe(0.3);
});

it('lets a receipt line remember the unit and prices it was entered with', function (): void {
    expect(Schema::hasColumns('pos_purchase_receipt_lines', [
        'purchase_unit', 'purchase_quantity', 'unit_price', 'unit_cost',
    ]))->toBeTrue();
});

it('lets a count line record late pre-count movements and its reconciliation waste', function (): void {
    expect(Schema::hasColumns('pos_stock_count_lines', ['late_movement_units', 'waste_record_id']))->toBeTrue();

    $migration = require database_path('migrations/2026_10_02_000003_add_late_movement_tracking_to_pos_stock_count_lines.php');
    $migration->down();
    expect(Schema::hasColumn('pos_stock_count_lines', 'late_movement_units'))->toBeFalse()
        ->and(Schema::hasColumn('pos_stock_count_lines', 'waste_record_id'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumns('pos_stock_count_lines', ['late_movement_units', 'waste_record_id']))->toBeTrue();
});
