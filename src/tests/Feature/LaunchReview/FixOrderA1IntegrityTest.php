<?php

declare(strict_types=1);

/*
 * LAUNCH review add-on, fix order A-1 — pos:check-tenant-integrity covers:
 *  - L1: removes_ingredient_id only on an option of a Remove group;
 *  - L5: a container on a transfer / count / purchase / waste / restock line
 *        belongs to that line's item and the document's company; the
 *        breakdown names a container of its company, a leaf one only;
 *  - a main combo slot is a single pick (tester call 15);
 *  - part C review: a Remove or quick-instruction option carries no stock
 *        (legacy ingredient fields, consumption lines or a linked product).
 * Each check is clean on good rows and reports exactly the bad one.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Services\TenantIntegrityChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function a1Ingredient(int $companyId, string $name): int
{
    return (int) DB::table('pos_ingredients')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'name' => $name, 'unit' => 'ml', 'created_at' => now(), 'updated_at' => now()]);
}

function a1Container(int $companyId, int $ingredientId, string $name, string $factor, array $extra = []): int
{
    return (int) DB::table('pos_ingredient_units')->insertGetId($extra + ['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'ingredient_id' => $ingredientId, 'name' => $name, 'factor' => $factor, 'created_at' => now(), 'updated_at' => now()]);
}

function a1Product(int $companyId, string $name, array $extra = []): int
{
    return (int) DB::table('pos_products')->insertGetId($extra + ['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'name' => $name, 'base_price' => '1.000', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array<string, list<int>> check => sample ids, for the checks that report something */
function a1Flagged(): array
{
    return collect(app(TenantIntegrityChecks::class)->run())->filter(fn (array $r): bool => $r['count'] > 0)
        ->map(fn (array $r): array => $r['sample_ids'])->all();
}

it('flags removes_ingredient_id on an option outside a Remove group (L1)', function (): void {
    $company = Company::factory()->create();
    $garlic = a1Ingredient($company->id, 'Garlic');
    $burger = a1Product($company->id, 'Burger');
    $group = fn (string $name, string $kind, ?int $owner = null) => (int) DB::table('pos_addon_groups')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => $name, 'kind' => $kind, 'owner_product_id' => $owner,
        'created_at' => now(), 'updated_at' => now()]);
    $addon = fn (int $groupId, string $name) => (int) DB::table('pos_addons')->insertGetId(['uuid' => (string) Str::uuid(),
        'company_id' => $company->id, 'add_on_group_id' => $groupId, 'name' => $name, 'removes_ingredient_id' => $garlic,
        'created_at' => now(), 'updated_at' => now()]);
    $addon($group('Remove', 'remove', $burger), 'NO Garlic');
    expect(a1Flagged())->toBe([]);

    $extra = $addon($group('Extras', 'extras'), 'Extra garlic');
    $note = $addon($group('Instructions', 'instructions'), 'Garlic on the side');
    expect(a1Flagged())->toBe(['addon_removes_ingredient_not_remove_group' => [$extra, $note]]);
});

it('flags a Remove or quick-instruction option that carries stock (part C review)', function (): void {
    $company = Company::factory()->create();
    $garlic = a1Ingredient($company->id, 'Garlic');
    $burger = a1Product($company->id, 'Burger');
    $cup = a1Product($company->id, 'Cup', ['is_internal' => true]);
    $remove = (int) DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'name' => 'Remove', 'kind' => 'remove', 'owner_product_id' => $burger, 'created_at' => now(), 'updated_at' => now()]);
    $instructions = (int) DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'name' => 'Instructions', 'kind' => 'instructions', 'created_at' => now(), 'updated_at' => now()]);
    $extras = (int) DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'name' => 'Extras', 'created_at' => now(), 'updated_at' => now()]);
    $addon = fn (int $groupId, string $name, array $extra = []) => (int) DB::table('pos_addons')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'add_on_group_id' => $groupId, 'name' => $name,
        'created_at' => now(), 'updated_at' => now()]);
    $addon($remove, 'NO Garlic', ['removes_ingredient_id' => $garlic]);
    $addon($instructions, 'Well done');
    $extraGarlic = $addon($extras, 'Extra garlic', ['ingredient_id' => $garlic, 'ingredient_qty' => '5', 'ingredient_unit' => 'g']);
    DB::table('pos_addon_consumptions')->insert(['add_on_id' => $extraGarlic, 'ingredient_id' => $garlic, 'direction' => 'add',
        'quantity' => '5', 'unit' => 'g', 'created_at' => now(), 'updated_at' => now()]);
    expect(a1Flagged())->toBe([]);

    $legacy = $addon($remove, 'NO Onion', ['ingredient_id' => $garlic, 'ingredient_qty' => '5']);
    $lined = $addon($instructions, 'Extra crispy');
    DB::table('pos_addon_consumptions')->insert(['add_on_id' => $lined, 'ingredient_id' => $garlic, 'direction' => 'add',
        'quantity' => '1', 'unit' => 'g', 'created_at' => now(), 'updated_at' => now()]);
    $linked = $addon($instructions, 'In a cup', ['linked_product_id' => $cup]);

    expect(a1Flagged())->toBe(['addon_tap_list_option_with_stock' => [$legacy, $lined, $linked]]);
});

it('flags a container on a stock document line of another item or company (L5)', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $a->id]);
    $other = Branch::factory()->create(['company_id' => $a->id]);
    $milk = a1Ingredient($a->id, 'Milk');
    $cream = a1Ingredient($a->id, 'Cream');
    $foreignMilk = a1Ingredient($b->id, 'Milk');
    $bottle = a1Container($a->id, $milk, 'bottle', '1000');
    $creamBottle = a1Container($a->id, $cream, 'bottle', '500');
    $foreignBottle = a1Container($b->id, $foreignMilk, 'bottle', '1000');
    $cups = a1Product($a->id, 'Cups', ['is_internal' => true]);
    $lids = a1Product($a->id, 'Lids', ['is_internal' => true]);
    $pack = fn (int $productId) => (int) DB::table('pos_product_packs')->insertGetId(['uuid' => (string) Str::uuid(),
        'company_id' => $a->id, 'product_id' => $productId, 'name' => 'box', 'pieces' => '50', 'created_at' => now(), 'updated_at' => now()]);
    $cupBox = $pack($cups);
    $lidBox = $pack($lids);
    $t = ['created_at' => now(), 'updated_at' => now()];

    $transfer = DB::table('pos_branch_transfers')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $a->id,
        'from_branch_id' => $branch->id, 'to_branch_id' => $other->id] + $t);
    $transferLine = DB::table('pos_branch_transfer_lines')->insertGetId(['branch_transfer_id' => $transfer, 'ingredient_id' => $milk,
        'quantity' => '2500', 'unit_at_set' => 'ml'] + $t);
    $count = DB::table('pos_stock_counts')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $a->id, 'branch_id' => $branch->id] + $t);
    $countLine = DB::table('pos_stock_count_lines')->insertGetId(['stock_count_id' => $count, 'ingredient_id' => $milk,
        'counted_units' => '2000', 'expected_units' => '2000', 'variance_units' => '0']);
    $receipt = DB::table('pos_purchase_receipts')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $a->id] + $t);
    $request = DB::table('pos_restock_requests')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $a->id, 'branch_id' => $branch->id] + $t);
    $child = fn (string $table, string $parent, int $parentId, int $container) => (int) DB::table($table)->insertGetId([
        $parent => $parentId, 'company_id' => $a->id, 'container_id' => $container, 'container_label' => 'bottle',
        'container_factor' => '1000', 'pieces' => '1'] + $t);
    $receiptLine = fn (array $extra) => (int) DB::table('pos_purchase_receipt_lines')->insertGetId($extra + [
        'purchase_receipt_id' => $receipt, 'item_name' => 'Item', 'quantity' => '1'] + $t);
    $waste = fn (int $container) => (int) DB::table('pos_waste_records')->insertGetId(['uuid' => (string) Str::uuid(),
        'branch_id' => $branch->id, 'ingredient_id' => $milk, 'quantity' => '500', 'reason' => 'spoiled', 'unit_at_set' => 'ml',
        'container_id' => $container, 'pieces' => '1'] + $t);
    $restockLine = fn (int $ingredient, int $container) => (int) DB::table('pos_restock_request_lines')->insertGetId([
        'restock_request_id' => $request, 'ingredient_id' => $ingredient, 'quantity_requested' => '1000', 'unit_at_set' => 'ml',
        'container_id' => $container, 'pieces' => '1'] + $t);

    // Good rows on every document.
    $child('pos_branch_transfer_line_containers', 'branch_transfer_line_id', $transferLine, $bottle);
    $child('pos_stock_count_line_containers', 'stock_count_line_id', $countLine, $bottle);
    $receiptLine(['item_type' => 'ingredient', 'ingredient_id' => $milk, 'container_id' => $bottle]);
    $receiptLine(['item_type' => 'product', 'product_id' => $cups, 'pack_id' => $cupBox]);
    $waste($bottle);
    $restockLine($milk, $bottle);
    expect(a1Flagged())->toBe([]);

    // One bad row of each kind: another item's container, another company's container, another product's pack.
    $bad = [
        'transfer_line_container_item' => [
            $child('pos_branch_transfer_line_containers', 'branch_transfer_line_id', $transferLine, $creamBottle),
            $child('pos_branch_transfer_line_containers', 'branch_transfer_line_id', $transferLine, $foreignBottle),
        ],
        'count_line_container_item' => [$child('pos_stock_count_line_containers', 'stock_count_line_id', $countLine, $creamBottle)],
        'receipt_line_container_item' => [
            $receiptLine(['item_type' => 'ingredient', 'ingredient_id' => $milk, 'container_id' => $foreignBottle]),
            $receiptLine(['item_type' => 'product', 'product_id' => $cups, 'pack_id' => $lidBox]),
        ],
        'waste_container_item' => [$waste($creamBottle)],
        'restock_line_container_item' => [$restockLine($cream, $bottle)],
    ];
    expect(a1Flagged())->toBe($bad);
});

it('flags a breakdown kept in a non-leaf or another company\'s container, and a main slot that is not a single pick', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $a->id]);
    $milk = a1Ingredient($a->id, 'Milk');
    $bottle = a1Container($a->id, $milk, 'bottle', '1000');
    $crate = a1Container($a->id, $milk, 'crate', '12000', ['contains_unit_id' => $bottle, 'contains_quantity' => '12']);
    $stray = a1Container($b->id, $milk, 'can', '330');
    $balance = fn (int $container, ?int $branchId) => (int) DB::table('pos_stock_container_balances')->insertGetId([
        'company_id' => $a->id, 'branch_id' => $branchId, 'ingredient_id' => $milk, 'container_id' => $container, 'pieces' => '2',
        'created_at' => now(), 'updated_at' => now()]);
    $combo = a1Product($a->id, 'Meal', ['product_type' => 'combo']);
    $slot = fn (string $name, bool $main, int $min, int $max) => (int) DB::table('pos_combo_slots')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $a->id, 'combo_product_id' => $combo, 'name' => $name, 'is_main' => $main,
        'min_choices' => $min, 'max_choices' => $max, 'created_at' => now(), 'updated_at' => now()]);
    $balance($bottle, $branch->id);
    $slot('Burger', true, 1, 1);
    $slot('Sides', false, 2, 2);
    // The stray container itself is reported (another company than its item).
    expect(array_keys(a1Flagged()))->toBe(['ingredient_container_company']);

    $nonLeaf = $balance($crate, null);
    $foreign = $balance($stray, $branch->id);
    $other = a1Product($a->id, 'Box', ['product_type' => 'combo']);
    $wide = (int) DB::table('pos_combo_slots')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $a->id,
        'combo_product_id' => $other, 'name' => 'Burgers', 'is_main' => true, 'min_choices' => 1, 'max_choices' => 4,
        'created_at' => now(), 'updated_at' => now()]);

    $flagged = a1Flagged();
    expect($flagged['container_balance_not_leaf'])->toBe([$nonLeaf])
        ->and($flagged['container_balance_company'])->toBe([$foreign])
        ->and($flagged['combo_main_slot_not_single'])->toBe([$wide]);
});
