<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH packaging add-on §2 tester call 3 — the same item may appear on
 * several lines of one product (or of one add-on option and direction) ONLY
 * when their "Used for" ticks do not overlap: napkin ×1 for dine in, napkin
 * ×3 for to go and delivery.
 *
 * Each of today's four unique indexes becomes four partial unique indexes,
 * one per order-type bit, on the same columns:
 *
 *   UNIQUE (cols) WHERE (order_types & bit) <> 0     bit = 1, 2, 4, 8
 *
 * Two lines of one item that share any tick both fall in that tick's index
 * and collide, so an overlap is refused by the database itself; lines with
 * disjoint ticks never meet in one index. While every line is 15 (all four
 * ticks, the default) each index holds every row: exactly today's rule.
 * The portal refuses an overlap first (with a message), and
 * pos:check-tenant-integrity reports any overlap it finds.
 *
 * down() puts the old uniques back and refuses to run once an item has two
 * lines on one product / option and direction (the old index cannot hold
 * them); delete or merge those lines first.
 */
return new class extends Migration
{
    /** old unique index => [table, columns, partial-index stem] */
    public const UNIQUES = [
        'pos_product_recipes_product_ingredient_unique' => ['pos_product_recipes', ['product_id', 'ingredient_id'], 'pos_product_recipes_ingredient_type'],
        'pos_product_components_pair_unique' => ['pos_product_components', ['product_id', 'component_product_id'], 'pos_product_components_pair_type'],
        'pos_addon_consumptions_ing_dir_unique' => ['pos_addon_consumptions', ['add_on_id', 'ingredient_id', 'direction'], 'pos_addon_consumptions_ing_dir_type'],
        'pos_addon_consumptions_prod_dir_unique' => ['pos_addon_consumptions', ['add_on_id', 'component_product_id', 'direction'], 'pos_addon_consumptions_prod_dir_type'],
    ];

    public const BITS = [1, 2, 4, 8];

    public function up(): void
    {
        foreach (self::UNIQUES as $old => [$table, $columns, $stem]) {
            foreach (self::BITS as $bit) {
                DB::statement(sprintf('CREATE UNIQUE INDEX "%s%d_unique" ON "%s" (%s) WHERE ("order_types" & %d) <> 0',
                    $stem, $bit, $table, self::quoted($columns), $bit));
            }
            Schema::table($table, function (Blueprint $blueprint) use ($old): void {
                $blueprint->dropUnique($old);
            });
        }
    }

    public function down(): void
    {
        foreach (self::UNIQUES as [$table, $columns]) {
            // NULL refs never collide in a unique index (an add-on line names
            // an ingredient OR a product), so only lines naming the item count.
            $clash = DB::table($table)->whereNotNull($columns[1])->select($columns)->groupBy($columns)
                ->havingRaw('COUNT(*) > 1')->exists();
            if ($clash) {
                throw new RuntimeException("Cannot roll back 2026_10_06_110002: {$table} has an item on two lines ("
                    .implode(', ', $columns).') for different order types; delete or merge them first.');
            }
        }

        foreach (self::UNIQUES as $old => [$table, $columns, $stem]) {
            Schema::table($table, function (Blueprint $blueprint) use ($old, $columns): void {
                $blueprint->unique($columns, $old);
            });
            foreach (self::BITS as $bit) {
                DB::statement(sprintf('DROP INDEX IF EXISTS "%s%d_unique"', $stem, $bit));
            }
        }
    }

    /** @param list<string> $columns */
    private static function quoted(array $columns): string
    {
        return implode(', ', array_map(static fn (string $column): string => '"'.$column.'"', $columns));
    }
};
