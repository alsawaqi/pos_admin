<?php

declare(strict_types=1);

/*
 * LAUNCH combo add-on, Part A item 1 (LAUNCH-COMBO_WORK_ORDER.md §2.2, §2.3):
 * pos_admin owns the schema of combos and meals as lists of lines, the
 * retirement of the old choice slots and the order-line snapshots.
 *
 * The suites run on SQLite; the Postgres-only CHECK constraints are proven by
 * the live-copy rehearsal's must-fail SQL (rehearse-combo.sh).
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

function cbProduct(int $companyId, string $name, array $extra = []): int
{
    return (int) DB::table('pos_products')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'base_price' => '1.000',
        'stock_mode' => 'untracked', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function cbCategory(int $companyId, string $name): int
{
    return (int) DB::table('pos_product_categories')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function cbMeal(int $companyId, array $extra = []): int
{
    return (int) DB::table('pos_meals')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => 'meal', 'name_ar' => 'وجبة', 'meal_price' => '1.200',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @param array<string, mixed> $extra */
function cbLine(int $companyId, array $extra): int
{
    return (int) DB::table('pos_combo_lines')->insertGetId($extra + [
        'company_id' => $companyId, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function cbOrderItem(int $orderId, ?int $productId, array $extra = []): int
{
    return (int) DB::table('pos_order_items')->insertGetId($extra + [
        'order_id' => $orderId, 'product_id' => $productId, 'product_name_snapshot' => 'Item', 'qty' => '1.000',
        'unit_price_snapshot' => '1.000', 'line_total' => '1.000', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function cbOrder(int $companyId, int $branchId): int
{
    return (int) DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId, 'order_type' => 'quick',
        'source' => 'pos', 'status' => 'paid', 'subtotal' => '3.200', 'tax_total' => '0.000', 'grand_total' => '3.200',
        'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array<string, list<int>> check => sample ids, for the combo / meal / add-on price checks that report something */
function cbFlagged(): array
{
    return collect(app(TenantIntegrityChecks::class)->run())
        ->filter(fn (array $r, string $check): bool => $r['count'] > 0
            && (str_starts_with($check, 'combo_') || str_starts_with($check, 'meal_') || $check === 'order_item_meal_company'
                || in_array($check, ['addon_remove_option_priced', 'addon_extras_option_negative', 'addon_instruction_option_priced'], true)))
        ->map(fn (array $r): array => $r['sample_ids'])->all();
}

it('creates the meal and combo-line tables and the order-line snapshot columns', function (): void {
    expect(Schema::hasColumns('pos_meals', ['id', 'uuid', 'company_id', 'name', 'name_ar', 'meal_price', 'status',
        'on_sale_from', 'on_sale_until', 'sort_order', 'created_at', 'updated_at', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('pos_meal_categories', ['id', 'company_id', 'meal_id', 'category_id']))->toBeTrue()
        ->and(Schema::hasColumns('pos_meal_excluded_products', ['id', 'company_id', 'meal_id', 'product_id']))->toBeTrue()
        ->and(Schema::hasColumns('pos_combo_lines', ['id', 'company_id', 'combo_product_id', 'meal_id', 'kind', 'product_id',
            'quantity', 'category_id', 'pick_count', 'name', 'name_ar', 'sort_order']))->toBeTrue()
        ->and(Schema::hasColumns('pos_combo_line_upgrades', ['id', 'company_id', 'line_id', 'product_id', 'upgrade_price', 'sort_order']))->toBeTrue()
        ->and(Schema::hasColumns('pos_combo_line_items', ['id', 'company_id', 'line_id', 'product_id', 'excluded', 'extra_price']))->toBeTrue()
        ->and(Schema::hasColumns('pos_order_items', ['meal_id', 'combo_line_id', 'combo_child_kind', 'allocated_revenue_baisas']))->toBeTrue()
        // The old slot model is gone.
        ->and(Schema::hasTable('pos_combo_slots'))->toBeFalse()
        ->and(Schema::hasTable('pos_combo_slot_options'))->toBeFalse();
});

it('keeps one upgrade and one override per product on a line, one category and one untick per product on a meal', function (): void {
    $company = Company::factory()->create();
    $box = cbProduct($company->id, 'Family box', ['product_type' => 'combo']);
    $fries = cbProduct($company->id, 'Fries');
    $loaded = cbProduct($company->id, 'Loaded fries');
    $cola = cbProduct($company->id, 'Cola');
    $drinks = cbCategory($company->id, 'Drinks');
    $fixed = cbLine($company->id, ['combo_product_id' => $box, 'kind' => 'fixed', 'product_id' => $fries, 'quantity' => 1]);
    $choice = cbLine($company->id, ['combo_product_id' => $box, 'kind' => 'choice', 'category_id' => $drinks, 'pick_count' => 4,
        'name' => 'Drinks', 'name_ar' => 'المشروبات', 'sort_order' => 1]);
    $upgrade = ['company_id' => $company->id, 'line_id' => $fixed, 'product_id' => $loaded, 'upgrade_price' => '0.800', 'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_combo_line_upgrades')->insert($upgrade);
    expect(fn () => DB::table('pos_combo_line_upgrades')->insert($upgrade))->toThrow(QueryException::class);
    $item = ['company_id' => $company->id, 'line_id' => $choice, 'product_id' => $cola, 'excluded' => true, 'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_combo_line_items')->insert($item);
    expect(fn () => DB::table('pos_combo_line_items')->insert($item))->toThrow(QueryException::class)
        ->and((float) DB::table('pos_combo_line_items')->value('extra_price'))->toBe(0.0);

    $meal = cbMeal($company->id);
    $burgers = cbCategory($company->id, 'Burgers');
    $category = ['company_id' => $company->id, 'meal_id' => $meal, 'category_id' => $burgers, 'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_meal_categories')->insert($category);
    expect(fn () => DB::table('pos_meal_categories')->insert($category))->toThrow(QueryException::class);
    $untick = ['company_id' => $company->id, 'meal_id' => $meal, 'product_id' => $fries, 'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_meal_excluded_products')->insert($untick);
    expect(fn () => DB::table('pos_meal_excluded_products')->insert($untick))->toThrow(QueryException::class)
        ->and(DB::table('pos_meals')->where('id', $meal)->value('status'))->toBe('active');

    // A product named by a line or an upgrade cannot be hard-deleted; the combo takes its lines with it.
    expect(fn () => DB::table('pos_products')->where('id', $loaded)->delete())->toThrow(QueryException::class);
    DB::table('pos_products')->where('id', $box)->delete();
    expect(DB::table('pos_combo_lines')->count())->toBe(0)
        ->and(DB::table('pos_combo_line_upgrades')->count())->toBe(0)
        ->and(DB::table('pos_combo_line_items')->count())->toBe(0);
});

it('retires the slot tables and switches a slot combo inactive, never another product', function (): void {
    $migration = require database_path('migrations/2026_10_07_100002_retire_pos_combo_slots.php');
    $migration->down();
    expect(Schema::hasTable('pos_combo_slots'))->toBeTrue()->and(Schema::hasTable('pos_combo_slot_options'))->toBeTrue();

    $company = Company::factory()->create();
    $slotCombo = cbProduct($company->id, 'tet', ['product_type' => 'combo', 'updated_at' => '2026-10-01 08:00:00']);
    $plainCombo = cbProduct($company->id, 'Empty combo', ['product_type' => 'combo', 'updated_at' => '2026-10-01 08:00:00']);
    $burger = cbProduct($company->id, 'Burger', ['updated_at' => '2026-10-01 08:00:00']);
    $slot = (int) DB::table('pos_combo_slots')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'combo_product_id' => $slotCombo, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_combo_slot_options')->insert(['company_id' => $company->id, 'slot_id' => $slot, 'product_id' => $burger,
        'created_at' => now(), 'updated_at' => now()]);

    $migration->up();
    expect(Schema::hasTable('pos_combo_slots'))->toBeFalse()->and(Schema::hasTable('pos_combo_slot_options'))->toBeFalse()
        ->and(DB::table('pos_products')->where('id', $slotCombo)->value('status'))->toBe('inactive')
        ->and((string) DB::table('pos_products')->where('id', $slotCombo)->value('updated_at'))->not->toBe('2026-10-01 08:00:00')
        ->and(DB::table('pos_products')->where('id', $plainCombo)->value('status'))->toBe('active')
        ->and((string) DB::table('pos_products')->where('id', $plainCombo)->value('updated_at'))->toBe('2026-10-01 08:00:00')
        ->and(DB::table('pos_products')->where('id', $burger)->value('status'))->toBe('active');
});

it('stores a meal parent with its children, their kinds and allocated revenue, and lets them go with the parent', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $meal = cbMeal($company->id);
    $burger = cbProduct($company->id, 'Beef burger');
    $fries = cbProduct($company->id, 'Fries');
    $order = cbOrder($company->id, $branch->id);
    $parent = cbOrderItem($order, null, ['meal_id' => $meal, 'product_name_snapshot' => 'Beef burger meal', 'unit_price_snapshot' => '3.200', 'line_total' => '3.200']);
    $main = cbOrderItem($order, $burger, ['parent_order_item_id' => $parent, 'combo_child_kind' => 'main', 'allocated_revenue_baisas' => 2133,
        'unit_price_snapshot' => '0.000', 'line_total' => '0.000']);
    $side = cbOrderItem($order, $fries, ['parent_order_item_id' => $parent, 'combo_child_kind' => 'fixed', 'combo_line_id' => 9,
        'allocated_revenue_baisas' => 1067, 'unit_price_snapshot' => '0.000', 'line_total' => '0.000']);

    $rows = DB::table('pos_order_items')->whereIn('id', [$parent, $main, $side])->orderBy('id')->get();
    expect((int) $rows[0]->meal_id)->toBe($meal)->and($rows[0]->product_id)->toBeNull()->and($rows[0]->combo_child_kind)->toBeNull()
        ->and($rows[1]->combo_child_kind)->toBe('main')->and((int) $rows[2]->combo_line_id)->toBe(9)
        ->and((int) $rows[1]->allocated_revenue_baisas + (int) $rows[2]->allocated_revenue_baisas)->toBe(3200)
        ->and(cbFlagged())->toBe([]);

    DB::table('pos_order_items')->where('id', $parent)->delete();
    expect(DB::table('pos_order_items')->where('order_id', $order)->count())->toBe(0);
});

it('reports combo and meal rows that reach another merchant or break the line rules', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $branchA = Branch::factory()->for($a)->create();
    $branchB = Branch::factory()->for($b)->create();
    $box = cbProduct($a->id, 'Family box', ['product_type' => 'combo']);
    $burger = cbProduct($a->id, 'Burger');
    $loaded = cbProduct($a->id, 'Loaded fries');
    $cola = cbProduct($a->id, 'Cola');
    $drinks = cbCategory($a->id, 'Drinks');
    $burgers = cbCategory($a->id, 'Burgers');
    $fixed = cbLine($a->id, ['combo_product_id' => $box, 'kind' => 'fixed', 'product_id' => $burger, 'quantity' => 2]);
    $choice = cbLine($a->id, ['combo_product_id' => $box, 'kind' => 'choice', 'category_id' => $drinks, 'pick_count' => 1, 'name' => 'Drink']);
    DB::table('pos_combo_line_upgrades')->insert(['company_id' => $a->id, 'line_id' => $fixed, 'product_id' => $loaded, 'upgrade_price' => '0.800', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_combo_line_items')->insert(['company_id' => $a->id, 'line_id' => $choice, 'product_id' => $cola, 'extra_price' => '0.300', 'created_at' => now(), 'updated_at' => now()]);
    $meal = cbMeal($a->id);
    DB::table('pos_meal_categories')->insert(['company_id' => $a->id, 'meal_id' => $meal, 'category_id' => $burgers, 'created_at' => now(), 'updated_at' => now()]);
    cbLine($a->id, ['meal_id' => $meal, 'kind' => 'choice', 'category_id' => $drinks, 'pick_count' => 1, 'name' => 'Drink']);
    DB::table('pos_products')->where('id', $burger)->update(['category_id' => $burgers]);
    // A second active meal that unticks the burger does not clash.
    $other = cbMeal($a->id, ['name' => 'Big meal']);
    DB::table('pos_meal_categories')->insert(['company_id' => $a->id, 'meal_id' => $other, 'category_id' => $burgers, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_meal_excluded_products')->insert(['company_id' => $a->id, 'meal_id' => $other, 'product_id' => $burger, 'created_at' => now(), 'updated_at' => now()]);
    expect(cbFlagged())->toBe([]);

    // Tenancy: another merchant's product, category or meal can never be used.
    $foreignProduct = cbProduct($b->id, 'Their burger');
    $foreignCategory = cbCategory($b->id, 'Their drinks');
    $foreignFixed = cbLine($a->id, ['combo_product_id' => $box, 'kind' => 'fixed', 'product_id' => $foreignProduct, 'quantity' => 1]);
    $foreignChoice = cbLine($a->id, ['combo_product_id' => $box, 'kind' => 'choice', 'category_id' => $foreignCategory, 'pick_count' => 1, 'name' => 'X']);
    $wrongOwner = cbLine($b->id, ['combo_product_id' => $box, 'kind' => 'fixed', 'product_id' => $burger, 'quantity' => 1]);
    $foreignUpgrade = (int) DB::table('pos_combo_line_upgrades')->insertGetId(['company_id' => $a->id, 'line_id' => $fixed, 'product_id' => $foreignProduct, 'created_at' => now(), 'updated_at' => now()]);
    $upgradeOnChoice = (int) DB::table('pos_combo_line_upgrades')->insertGetId(['company_id' => $a->id, 'line_id' => $choice, 'product_id' => $burger, 'created_at' => now(), 'updated_at' => now()]);
    $foreignItem = (int) DB::table('pos_combo_line_items')->insertGetId(['company_id' => $a->id, 'line_id' => $choice, 'product_id' => $foreignProduct, 'created_at' => now(), 'updated_at' => now()]);
    $foreignMealCategory = (int) DB::table('pos_meal_categories')->insertGetId(['company_id' => $a->id, 'meal_id' => $meal, 'category_id' => $foreignCategory, 'created_at' => now(), 'updated_at' => now()]);
    // A combo inside a combo, and a line owned by a standard product.
    $nested = cbLine($a->id, ['combo_product_id' => $box, 'kind' => 'fixed', 'product_id' => cbProduct($a->id, 'Other box', ['product_type' => 'combo']), 'quantity' => 1]);
    $notCombo = cbLine($a->id, ['combo_product_id' => $cola, 'kind' => 'fixed', 'product_id' => $burger, 'quantity' => 1]);
    // An order line naming another merchant's meal.
    $order = cbOrder($a->id, $branchA->id);
    $foreignMealLine = cbOrderItem($order, null, ['meal_id' => cbMeal($b->id)]);
    cbOrder($b->id, $branchB->id);

    $flagged = cbFlagged();
    expect($flagged['combo_line_company'])->toEqualCanonicalizing([$foreignFixed, $foreignChoice, $wrongOwner])
        ->and($flagged['combo_upgrade_company'])->toEqualCanonicalizing([$foreignUpgrade, $upgradeOnChoice])
        ->and($flagged['combo_choice_item_company'])->toBe([$foreignItem])
        ->and($flagged['meal_refs_company'])->toBe([$foreignMealCategory])
        ->and($flagged['combo_line_item_type'])->toBe([$nested])
        ->and($flagged['combo_line_owner_not_combo'])->toBe([$notCombo])
        ->and($flagged['order_item_meal_company'])->toBe([$foreignMealLine])
        ->and($flagged)->not->toHaveKey('meal_main_in_two_meals');
});

it('reports a main covered by two active meals (the clash rule), never an inactive or deleted one', function (): void {
    $company = Company::factory()->create();
    $burgers = cbCategory($company->id, 'Burgers');
    $beef = cbProduct($company->id, 'Beef burger', ['category_id' => $burgers]);
    $chicken = cbProduct($company->id, 'Chicken burger', ['category_id' => $burgers]);
    $first = cbMeal($company->id);
    $second = cbMeal($company->id, ['name' => 'Big meal']);
    foreach ([$first, $second] as $meal) {
        DB::table('pos_meal_categories')->insert(['company_id' => $company->id, 'meal_id' => $meal, 'category_id' => $burgers, 'created_at' => now(), 'updated_at' => now()]);
    }
    // The second meal unticks the beef burger only: the chicken burger clashes.
    DB::table('pos_meal_excluded_products')->insert(['company_id' => $company->id, 'meal_id' => $second, 'product_id' => $beef, 'created_at' => now(), 'updated_at' => now()]);
    expect(cbFlagged())->toBe(['meal_main_in_two_meals' => [$chicken]]);

    DB::table('pos_meals')->where('id', $second)->update(['status' => 'inactive']);
    expect(cbFlagged())->toBe([]);
    DB::table('pos_meals')->where('id', $second)->update(['status' => 'active', 'deleted_at' => now()]);
    expect(cbFlagged())->toBe([]);
});

it('lets a Remove option lower the price, never raise it; Extras never go below 0; instructions stay free', function (): void {
    $company = Company::factory()->create();
    $burger = cbProduct($company->id, 'Burger');
    $group = fn (string $kind, ?int $owner = null) => (int) DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(),
        'company_id' => $company->id, 'name' => ucfirst($kind).' '.Str::random(4), 'kind' => $kind, 'owner_product_id' => $owner,
        'created_at' => now(), 'updated_at' => now()]);
    $option = fn (int $groupId, string $price) => (int) DB::table('pos_addons')->insertGetId(['uuid' => (string) Str::uuid(),
        'company_id' => $company->id, 'add_on_group_id' => $groupId, 'name' => 'Option '.Str::random(4), 'price_delta' => $price,
        'created_at' => now(), 'updated_at' => now()]);
    $remove = $group('remove', $burger);
    $extras = $group('extras');
    $instructions = $group('instructions');
    $option($remove, '-0.100');
    $option($remove, '0.000');
    $option($extras, '0.200');
    $option($instructions, '0.000');
    expect(cbFlagged())->toBe([]);

    $pricedRemove = $option($remove, '0.050');
    $negativeExtra = $option($extras, '-0.100');
    $pricedInstruction = $option($instructions, '-0.100');
    expect(cbFlagged())->toEqual([
        'addon_remove_option_priced' => [$pricedRemove],
        'addon_instruction_option_priced' => [$pricedInstruction],
        'addon_extras_option_negative' => [$negativeExtra],
    ]);
});

it('refuses to roll back once a combo line, a meal or a combo order line exists, and rolls back cleanly before', function (): void {
    $lines = require database_path('migrations/2026_10_07_100001_create_pos_meals_and_combo_lines.php');
    $snapshots = require database_path('migrations/2026_10_07_100003_add_meal_and_line_snapshots_to_pos_order_items.php');
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $meal = cbMeal($company->id);
    $order = cbOrder($company->id, $branch->id);
    $parent = cbOrderItem($order, null, ['meal_id' => $meal]);

    expect(fn () => $snapshots->down())->toThrow(RuntimeException::class, 'Cannot roll back 2026_10_07_100003')
        ->and(fn () => $lines->down())->toThrow(RuntimeException::class, 'Cannot roll back 2026_10_07_100001')
        ->and(Schema::hasColumn('pos_order_items', 'meal_id'))->toBeTrue()
        ->and(Schema::hasTable('pos_meals'))->toBeTrue();

    DB::table('pos_order_items')->where('id', $parent)->delete();
    DB::table('pos_meals')->delete();
    $snapshots->down();
    $lines->down();
    expect(Schema::hasColumn('pos_order_items', 'meal_id'))->toBeFalse()->and(Schema::hasTable('pos_meals'))->toBeFalse()
        ->and(Schema::hasTable('pos_combo_lines'))->toBeFalse();
    $lines->up();
    $snapshots->up();
    expect(Schema::hasColumn('pos_order_items', 'allocated_revenue_baisas'))->toBeTrue()->and(Schema::hasTable('pos_combo_line_items'))->toBeTrue();
});
