<?php

declare(strict_types=1);

/*
 * LAUNCH packaging add-on — the data contract (LAUNCH-PACKAGING_WORK_ORDER.md
 * §3 Part A / 1; pos_admin owns every migration, pos_api and pos_merchant
 * mirror them):
 *  110001 order_types (1 dine in, 2 quick, 4 to go, 8 delivery; default 15)
 *         on recipe, physical-item and add-on stock lines and pos_addons;
 *  110002 the same item on several lines only with disjoint ticks (one
 *         partial unique per bit replaces each old unique);
 *  110003 pos_order_packaging_lines (one list per merchant and type);
 *  110004 pos_orders.stock_order_type and packaging_snapshot_json;
 *  and pos:check-tenant-integrity covers every new relation.
 *
 * The suites run on SQLite; the Postgres-only CHECK constraints are proven by
 * the live-copy rehearsal's must-fail SQL (rehearse-pk.sh).
 */

use App\Models\Branch;
use App\Models\Company;
use App\Services\TenantIntegrityChecks;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pkMigration(string $name): object
{
    return require database_path('migrations/'.$name.'.php');
}

function pkIngredient(int $companyId, string $name, array $extra = []): int
{
    return (int) DB::table('pos_ingredients')->insertGetId($extra + ['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'name' => $name, 'unit' => 'g', 'created_at' => now(), 'updated_at' => now()]);
}

function pkProduct(int $companyId, string $name, array $extra = []): int
{
    return (int) DB::table('pos_products')->insertGetId($extra + ['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'name' => $name, 'base_price' => '1.000', 'stock_mode' => 'unit', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
}

function pkAddon(int $companyId): int
{
    $group = (int) DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'name' => 'Size', 'created_at' => now(), 'updated_at' => now()]);

    return (int) DB::table('pos_addons')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'add_on_group_id' => $group, 'name' => 'Large', 'created_at' => now(), 'updated_at' => now()]);
}

function pkRecipe(int $productId, int $ingredientId, int $mask): int
{
    return (int) DB::table('pos_product_recipes')->insertGetId(['product_id' => $productId, 'ingredient_id' => $ingredientId,
        'quantity' => '5', 'unit_at_set' => 'g', 'order_types' => $mask, 'created_at' => now(), 'updated_at' => now()]);
}

function pkComponent(int $productId, int $itemId, int $mask): int
{
    return (int) DB::table('pos_product_components')->insertGetId(['product_id' => $productId, 'component_product_id' => $itemId,
        'quantity' => '1', 'order_types' => $mask, 'created_at' => now(), 'updated_at' => now()]);
}

function pkStockLine(int $addOnId, array $ref, int $mask, string $direction = 'add'): int
{
    return (int) DB::table('pos_addon_consumptions')->insertGetId($ref + ['add_on_id' => $addOnId, 'direction' => $direction,
        'quantity' => '1', 'order_types' => $mask, 'created_at' => now(), 'updated_at' => now()]);
}

function pkPackaging(int $companyId, string $type, array $ref, array $extra = []): int
{
    return (int) DB::table('pos_order_packaging_lines')->insertGetId($extra + $ref + ['company_id' => $companyId, 'order_type' => $type,
        'quantity' => '1', 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array<string, list<int>> check => sample ids, for the checks that report something */
function pkFlagged(): array
{
    return collect(app(TenantIntegrityChecks::class)->run())->filter(fn (array $r): bool => $r['count'] > 0)
        ->map(fn (array $r): array => $r['sample_ids'])->all();
}

it('adds the "Used for" mask, all four types by default, to every stock line table (110001)', function (): void {
    foreach (['pos_product_recipes', 'pos_product_components', 'pos_addon_consumptions', 'pos_addons'] as $table) {
        expect(Schema::hasColumn($table, 'order_types'))->toBeTrue();
    }
    $company = Company::factory()->create();
    $latte = pkProduct($company->id, 'Latte', ['stock_mode' => 'ingredient']);
    $milk = pkIngredient($company->id, 'Milk');
    $addon = pkAddon($company->id);
    DB::table('pos_product_recipes')->insert(['product_id' => $latte, 'ingredient_id' => $milk, 'quantity' => '200', 'unit_at_set' => 'ml',
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_product_components')->insert(['product_id' => $latte, 'component_product_id' => pkProduct($company->id, 'Cup'),
        'quantity' => '1', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_addon_consumptions')->insert(['add_on_id' => $addon, 'ingredient_id' => $milk, 'quantity' => '100', 'unit' => 'ml',
        'created_at' => now(), 'updated_at' => now()]);

    foreach (['pos_product_recipes', 'pos_product_components', 'pos_addon_consumptions', 'pos_addons'] as $table) {
        expect(DB::table($table)->pluck('order_types')->map(fn ($v): int => (int) $v)->unique()->values()->all())->toBe([15]);
    }
});

it('lets one item sit on several lines only when their ticks do not overlap (110002)', function (): void {
    $company = Company::factory()->create();
    $latte = pkProduct($company->id, 'Latte', ['stock_mode' => 'ingredient']);
    $sugar = pkIngredient($company->id, 'Sugar');
    $napkin = pkProduct($company->id, 'Napkin');
    $addon = pkAddon($company->id);

    // Napkin ×1 dine in, ×3 to go + delivery; the same for a recipe ingredient and an add-on line.
    pkRecipe($latte, $sugar, 1);
    pkRecipe($latte, $sugar, 12);
    pkComponent($latte, $napkin, 1);
    pkComponent($latte, $napkin, 12);
    pkStockLine($addon, ['ingredient_id' => $sugar], 1);
    pkStockLine($addon, ['ingredient_id' => $sugar], 2);
    pkStockLine($addon, ['ingredient_id' => $sugar], 15, 'remove');
    pkStockLine($addon, ['component_product_id' => $napkin], 4);
    pkStockLine($addon, ['component_product_id' => $napkin], 8);

    foreach ([
        fn () => pkRecipe($latte, $sugar, 2 | 4),
        fn () => pkComponent($latte, $napkin, 1 | 2),
        fn () => pkStockLine($addon, ['ingredient_id' => $sugar], 3),
        fn () => pkStockLine($addon, ['ingredient_id' => $sugar], 8, 'remove'),
        fn () => pkStockLine($addon, ['component_product_id' => $napkin], 12),
    ] as $overlap) {
        expect($overlap)->toThrow(QueryException::class);
    }
    // Lines of another product never collide.
    pkRecipe(pkProduct($company->id, 'Mocha', ['stock_mode' => 'ingredient']), $sugar, 15);
    expect(pkFlagged())->toBe([]);

    // The old uniques are gone and the partial ones exist.
    $indexes = collect(Schema::getIndexes('pos_product_recipes'))->pluck('name')->all();
    expect($indexes)->not->toContain('pos_product_recipes_product_ingredient_unique')
        ->and($indexes)->toContain('pos_product_recipes_ingredient_type1_unique', 'pos_product_recipes_ingredient_type8_unique');
});

it('refuses to roll back the index swap once an item sits on two lines, and rolls back cleanly otherwise (110002)', function (): void {
    $company = Company::factory()->create();
    $latte = pkProduct($company->id, 'Latte', ['stock_mode' => 'ingredient']);
    $sugar = pkIngredient($company->id, 'Sugar');
    $addon = pkAddon($company->id);
    // A product line and an ingredient line on one option never count as a clash.
    pkStockLine($addon, ['ingredient_id' => $sugar], 15);
    pkStockLine($addon, ['component_product_id' => pkProduct($company->id, 'Cup')], 15);
    pkStockLine($addon, ['component_product_id' => pkProduct($company->id, 'Lid')], 15);
    $first = pkRecipe($latte, $sugar, 1);
    pkRecipe($latte, $sugar, 2);

    $migration = pkMigration('2026_10_06_110002_allow_same_item_lines_for_other_order_types');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'pos_product_recipes has an item on two lines');

    DB::table('pos_product_recipes')->where('id', '<>', $first)->delete();
    $migration->down();
    expect(collect(Schema::getIndexes('pos_product_recipes'))->pluck('name')->all())->toContain('pos_product_recipes_product_ingredient_unique');
    expect(fn () => pkRecipe($latte, $sugar, 2))->toThrow(QueryException::class);
    $migration->up();
    pkRecipe($latte, $sugar, 2);
});

it('keeps one live packaging line per merchant, type and item (110003)', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $napkin = pkIngredient($company->id, 'Napkin', ['unit' => 'piece']);
    $bag = pkProduct($company->id, 'Paper bag');

    $line = pkPackaging($company->id, 'to_go', ['ingredient_id' => $napkin], ['quantity' => '2', 'unit' => 'piece',
        'entered_unit' => '@piece', 'entered_quantity' => '2']);
    pkPackaging($company->id, 'to_go', ['product_id' => $bag]);
    pkPackaging($company->id, 'delivery', ['ingredient_id' => $napkin]);
    pkPackaging($company->id, 'delivery', ['product_id' => $bag]);
    expect(fn () => pkPackaging($company->id, 'to_go', ['ingredient_id' => $napkin]))->toThrow(QueryException::class)
        ->and(fn () => pkPackaging($company->id, 'to_go', ['product_id' => $bag]))->toThrow(QueryException::class);

    // A soft-deleted line frees its place.
    DB::table('pos_order_packaging_lines')->where('id', $line)->update(['deleted_at' => now()]);
    pkPackaging($company->id, 'to_go', ['ingredient_id' => $napkin]);
    expect(DB::table('pos_order_packaging_lines')->where('company_id', $company->id)->count())->toBe(5)
        ->and(pkFlagged())->toBe([]);

    // Deleting an item removes its packaging lines.
    $otherNapkin = pkIngredient($other->id, 'Napkin');
    pkPackaging($other->id, 'dine_in', ['ingredient_id' => $otherNapkin]);
    DB::table('pos_ingredients')->where('id', $otherNapkin)->delete();
    expect(DB::table('pos_order_packaging_lines')->where('company_id', $other->id)->count())->toBe(0);
});

it('adds the order stock stamp and the frozen packaging, empty on existing orders (110004)', function (): void {
    expect(Schema::hasColumns('pos_orders', ['stock_order_type', 'packaging_snapshot_json']))->toBeTrue();
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $id = (int) DB::table('pos_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'branch_id' => $branch->id, 'order_type' => 'quick', 'source' => 'pos', 'status' => 'paid', 'subtotal' => '1.000',
        'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $row = DB::table('pos_orders')->find($id);
    expect($row->stock_order_type)->toBeNull()->and($row->packaging_snapshot_json)->toBeNull();
});

it('reports a foreign or prep packaging item, foreign items in an order snapshot, and overlapping ticks', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $foreignNapkin = pkIngredient($other->id, 'Napkin');
    $sauce = pkIngredient($company->id, 'Sauce', ['is_prep' => true, 'prep_yield_quantity' => '100']);
    $foreignBag = pkProduct($other->id, 'Bag');
    $napkin = pkIngredient($company->id, 'Napkin');
    $bag = pkProduct($company->id, 'Bag');
    pkPackaging($company->id, 'to_go', ['ingredient_id' => $napkin]);
    pkPackaging($company->id, 'to_go', ['product_id' => $bag]);
    $order = fn (array $lines) => (int) DB::table('pos_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'branch_id' => $branch->id, 'order_type' => 'to_go', 'source' => 'pos', 'status' => 'paid', 'subtotal' => '1.000',
        'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        'stock_order_type' => 'to_go', 'packaging_snapshot_json' => json_encode(['order_type' => 'to_go', 'lines' => $lines])]);
    $order([['type' => 'ingredient', 'ingredient_id' => $napkin, 'qty' => 1], ['type' => 'product', 'product_id' => $bag, 'qty' => 1]]);
    expect(pkFlagged())->toBe([]);

    $foreignLine = pkPackaging($company->id, 'delivery', ['ingredient_id' => $foreignNapkin]);
    $foreignProductLine = pkPackaging($company->id, 'delivery', ['product_id' => $foreignBag]);
    $prepLine = pkPackaging($company->id, 'dine_in', ['ingredient_id' => $sauce]);
    $badOrder = $order([['type' => 'product', 'product_id' => $foreignBag, 'qty' => 1]]);
    $badIngredientOrder = $order([['type' => 'ingredient', 'ingredient_id' => $foreignNapkin, 'qty' => 1]]);

    // Lines that slipped past the per-bit uniques (dropped here to plant them).
    $latte = pkProduct($company->id, 'Latte', ['stock_mode' => 'ingredient']);
    $addon = pkAddon($company->id);
    foreach ([1, 2, 4, 8] as $bit) {
        DB::statement("DROP INDEX pos_product_recipes_ingredient_type{$bit}_unique");
        DB::statement("DROP INDEX pos_product_components_pair_type{$bit}_unique");
        DB::statement("DROP INDEX pos_addon_consumptions_ing_dir_type{$bit}_unique");
    }
    $r1 = pkRecipe($latte, $napkin, 3);
    $r2 = pkRecipe($latte, $napkin, 6);
    pkRecipe($latte, $napkin, 8);
    $c1 = pkComponent($latte, $bag, 15);
    $c2 = pkComponent($latte, $bag, 4);
    $a1 = pkStockLine($addon, ['ingredient_id' => $napkin], 9);
    $a2 = pkStockLine($addon, ['ingredient_id' => $napkin], 8);
    pkStockLine($addon, ['ingredient_id' => $napkin], 8, 'remove');

    expect(pkFlagged())->toBe([
        'recipe_line_ticks_overlap' => [$r1, $r2],
        'component_line_ticks_overlap' => [$c1, $c2],
        'addon_stock_line_ticks_overlap' => [$a1, $a2],
        'order_packaging_ref_company' => [$foreignLine, $foreignProductLine],
        'order_packaging_prep_item' => [$prepLine],
        'order_packaging_snapshot_company' => [$badOrder, $badIngredientOrder],
    ]);
});

it('refuses to roll back the order stamp once an order carries one, or frozen packaging (fix order PK-A1, M2)', function (string $column): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $id = (int) DB::table('pos_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'branch_id' => $branch->id, 'order_type' => 'to_go', 'source' => 'pos', 'status' => 'paid', 'subtotal' => '1.000',
        'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $migration = pkMigration('2026_10_06_110004_add_stock_order_type_and_packaging_to_pos_orders');

    DB::table('pos_orders')->where('id', $id)->update([$column => $column === 'stock_order_type' ? 'to_go'
        : json_encode(['order_type' => 'to_go', 'lines' => []])]);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot roll back 2026_10_06_110004');
    expect(Schema::hasColumns('pos_orders', ['stock_order_type', 'packaging_snapshot_json']))->toBeTrue();

    // With no stamped order the rollback runs.
    DB::table('pos_orders')->where('id', $id)->update(['stock_order_type' => null, 'packaging_snapshot_json' => null]);
    $migration->down();
    expect(Schema::hasColumn('pos_orders', 'stock_order_type'))->toBeFalse();
    $migration->up();
})->with(['stock_order_type', 'packaging_snapshot_json']);

it('refuses to roll back the packaging table while a live packaging line exists (fix order PK-A1, M2)', function (): void {
    $company = Company::factory()->create();
    $line = pkPackaging($company->id, 'to_go', ['product_id' => pkProduct($company->id, 'Paper bag')]);
    $migration = pkMigration('2026_10_06_110003_create_pos_order_packaging_lines_table');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot roll back 2026_10_06_110003');
    expect(Schema::hasTable('pos_order_packaging_lines'))->toBeTrue();

    // Only soft-deleted lines left: the rollback runs.
    DB::table('pos_order_packaging_lines')->where('id', $line)->update(['deleted_at' => now()]);
    $migration->down();
    expect(Schema::hasTable('pos_order_packaging_lines'))->toBeFalse();
    $migration->up();
});

it('reports a cooked product whose recipe holds an item on two lines (fix order PK-A1, M1)', function (): void {
    $company = Company::factory()->create();
    $sugar = pkIngredient($company->id, 'Sugar');
    $cake = pkProduct($company->id, 'Cake', ['stock_mode' => 'ingredient']);
    $first = pkRecipe($cake, $sugar, 1);
    $second = pkRecipe($cake, $sugar, 14);
    // Made to order: the two lines are the ticks working as meant.
    expect(pkFlagged())->toBe([]);

    DB::table('pos_products')->where('id', $cake)->update(['stock_mode' => 'cooked']);
    expect(pkFlagged())->toBe(['cooked_recipe_item_on_two_lines' => [$first, $second]]);
});
