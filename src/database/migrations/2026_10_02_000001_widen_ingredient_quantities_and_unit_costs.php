<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P2 P2-1 — "buy big, use small" precision.
 *
 * Stock is kept in one small unit (g / ml / piece) and costs per base unit
 * routinely fall below 0.001 OMR (flour at 0.350 OMR/kg is 0.00035 OMR/g),
 * so every per-base-unit INGREDIENT COST column moves from 3 to 6 decimals.
 * Every INGREDIENT QUANTITY column moves from 3 to 4 decimals, so existing
 * kg- and l-based ingredients can hold 0.3 g / 0.3 ml (0.0003 kg).
 *
 *   quantities  numeric(12,3) / (10,3) -> numeric(14,4)  (10 integer digits)
 *   unit costs  numeric(12,3) / (12,6) -> numeric(15,6)  ( 9 integer digits)
 *
 * WIDEN ONLY. Neither integer digits nor decimals ever shrink, and no value
 * is converted: Postgres re-encodes each value at the wider scale (exact).
 * A column the database already holds at least as wide is skipped, so the
 * migration can never narrow anything and re-running it changes nothing.
 * Money TOTALS stay numeric(12,3) (OMR baisa, rounded once at the end).
 *
 * Postgres only. SQLite (the test suites) has no numeric precision — values
 * keep full precision whatever the declared type — so there is nothing to
 * widen there. pos_merchant and pos_api mirror the wider definitions in their
 * test schemas.
 *
 * down() is deliberately a no-op: narrowing back could round data written
 * after the deploy, and the wider types are backward compatible.
 */
return new class extends Migration
{
    /** Ingredient quantity columns -> numeric(14,4). */
    public const QUANTITY_COLUMNS = [
        'pos_ingredients' => ['min_stock_threshold'],
        'pos_branch_stock' => ['quantity'],
        'pos_ingredient_stock' => ['quantity'],
        'pos_stock_movements' => ['quantity'],
        'pos_product_recipes' => ['quantity'],
        'pos_addons' => ['ingredient_qty'],
        'pos_addon_consumptions' => ['quantity'],
        'pos_waste_records' => ['quantity'],
        'pos_restock_request_lines' => ['quantity_requested', 'quantity_allocated'],
        'pos_branch_transfer_lines' => ['quantity'],
        'pos_ingredient_purchases' => ['pieces_received', 'units_received'],
        'pos_stock_count_lines' => ['counted_pieces', 'counted_units', 'expected_units', 'variance_units'],
        'pos_production_lines' => ['quantity'],
        'pos_purchase_receipt_lines' => ['quantity'],
    ];

    /** Per-base-unit cost columns -> numeric(15,6). */
    public const UNIT_COST_COLUMNS = [
        'pos_ingredients' => ['default_unit_cost'],
        'pos_stock_movements' => ['unit_cost_at_time'],
        'pos_waste_records' => ['unit_cost_at_time'],
        'pos_branch_transfer_lines' => ['unit_cost_at_time'],
        'pos_stock_count_lines' => ['unit_cost_at_time'],
        'pos_ingredient_purchases' => ['unit_cost'],
        // A cooked item's frozen per-piece recipe (production) cost.
        'pos_product_stock_movements' => ['unit_cost'],
    ];

    public const QUANTITY_TYPE = [14, 4];

    public const UNIT_COST_TYPE = [15, 6];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->statements(fn (string $table, array $columns): array => $this->currentTypes($table, $columns)) as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        // Never narrow — see the class comment.
    }

    /**
     * The ALTER statements for a database whose current numeric types are
     * reported by $currentTypes(table, columns) => [column => [precision, scale]].
     * One statement per table (a single rewrite, one lock); tables already
     * wide enough produce none.
     *
     * @param  callable(string, list<string>): array<string, array{0: int|null, 1: int|null}>  $currentTypes
     * @return list<string>
     */
    public function statements(callable $currentTypes): array
    {
        $statements = [];
        foreach ($this->plan() as $table => $columns) {
            $current = $currentTypes($table, array_keys($columns));
            $clauses = [];
            foreach ($columns as $column => [$precision, $scale]) {
                $type = self::widened($current[$column] ?? null, $precision, $scale);
                if ($type !== null) {
                    $clauses[] = sprintf('ALTER COLUMN "%s" TYPE numeric(%d,%d)', $column, $type[0], $type[1]);
                }
            }
            if ($clauses !== []) {
                $statements[] = sprintf('ALTER TABLE "%s" %s', $table, implode(', ', $clauses));
            }
        }

        return $statements;
    }

    /**
     * @return array<string, array<string, array{0: int, 1: int}>>
     */
    public function plan(): array
    {
        $plan = [];
        foreach (self::QUANTITY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $plan[$table][$column] = self::QUANTITY_TYPE;
            }
        }
        foreach (self::UNIT_COST_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $plan[$table][$column] = self::UNIT_COST_TYPE;
            }
        }

        return $plan;
    }

    /**
     * The type to ALTER to, or NULL when the column is already at least as
     * wide (in integer digits AND decimals) as the target. Never narrower than
     * what is there: integer digits and scale are each the larger of the two.
     *
     * @param  array{0: int|null, 1: int|null}|null  $current  [precision, scale]; null precision = unconstrained numeric
     * @return array{0: int, 1: int}|null
     */
    public static function widened(?array $current, int $precision, int $scale): ?array
    {
        if ($current === null) {
            // Column missing on this database: nothing to widen.
            return null;
        }
        [$curPrecision, $curScale] = $current;
        if ($curPrecision === null) {
            // Unconstrained numeric already holds any value.
            return null;
        }
        $curScale ??= 0;
        $curInteger = $curPrecision - $curScale;
        $integer = max($curInteger, $precision - $scale);
        $newScale = max($curScale, $scale);
        if ($curInteger >= $integer && $curScale >= $newScale) {
            return null;
        }

        return [$integer + $newScale, $newScale];
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, array{0: int|null, 1: int|null}>
     */
    private function currentTypes(string $table, array $columns): array
    {
        $rows = DB::select(
            'SELECT column_name, numeric_precision, numeric_scale FROM information_schema.columns '
            .'WHERE table_schema = current_schema() AND table_name = ? AND data_type = ?',
            [$table, 'numeric'],
        );
        $types = [];
        foreach ($rows as $row) {
            if (in_array($row->column_name, $columns, true)) {
                $types[$row->column_name] = [
                    $row->numeric_precision !== null ? (int) $row->numeric_precision : null,
                    $row->numeric_scale !== null ? (int) $row->numeric_scale : null,
                ];
            }
        }

        return $types;
    }
};
