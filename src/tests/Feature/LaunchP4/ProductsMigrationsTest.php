<?php

declare(strict_types=1);

/*
 * LAUNCH-P4 schema (pos_admin owns every migration; the data contract in
 * LAUNCH-P4_WORK_ORDER.md is shared with pos_api, pos_merchant, the till,
 * the handheld and QR web):
 *  - pos_products: product_type, sold_in_store, sold_on_delivery,
 *    branch_scope (backfilled 'selected' where branch rows exist today) and
 *    description_ar;
 *  - pos_product_delivery_prices: listed, and a nullable price;
 *  - pos_combo_slots, pos_combo_slot_options and pos_product_sold_out;
 *  - pos_orders.prices_include_tax and the combo-child columns on
 *    pos_order_items;
 *  - pos:check-tenant-integrity covers the new tables.
 *
 * The suites run on SQLite; the Postgres-only CHECK constraints are verified
 * in the live-copy rehearsal (rehearse-p4.sh, must-fail SQL).
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

function p4Product(int $companyId, string $name, array $extra = []): int
{
    return (int) DB::table('pos_products')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'base_price' => '1.000',
        'stock_mode' => 'untracked', 'status' => 'active', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
    ]);
}

function p4Slot(int $companyId, int $comboId, array $extra = []): int
{
    return (int) DB::table('pos_combo_slots')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'combo_product_id' => $comboId,
        'name' => 'Main', 'name_ar' => 'الطبق', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function p4Order(int $companyId, int $branchId): int
{
    return (int) DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId,
        'order_type' => 'quick', 'source' => 'pos', 'status' => 'paid', 'subtotal' => '2.000', 'tax_total' => '0.000',
        'grand_total' => '2.000', 'opened_at' => '2026-10-03 09:00:00', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function p4Item(int $orderId, int $productId, array $extra = []): int
{
    return (int) DB::table('pos_order_items')->insertGetId($extra + [
        'order_id' => $orderId, 'product_id' => $productId, 'product_name_snapshot' => 'Item', 'qty' => '1.000',
        'unit_price_snapshot' => '2.000', 'line_total' => '2.000', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('adds the product type, channels, branch scope and Arabic description with today\'s behaviour as default', function (): void {
    expect(Schema::hasColumns('pos_products', ['product_type', 'sold_in_store', 'sold_on_delivery', 'branch_scope', 'description_ar']))->toBeTrue();

    $company = Company::factory()->create();
    $id = p4Product($company->id, 'Latte');
    $row = DB::table('pos_products')->find($id);

    expect($row->product_type)->toBe('standard')
        ->and((bool) $row->sold_in_store)->toBeTrue()
        ->and((bool) $row->sold_on_delivery)->toBeTrue()
        ->and($row->branch_scope)->toBe('all')
        ->and($row->description_ar)->toBeNull();
});

it('marks a product that has branch rows today as sold at the selected branches only, without touching it otherwise', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $migration = require database_path('migrations/2026_10_03_100001_add_combo_type_and_channels_to_pos_products.php');
    $migration->down();

    $everywhere = (int) DB::table('pos_products')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Tea', 'base_price' => '0.500',
        'status' => 'active', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
    ]);
    $restricted = (int) DB::table('pos_products')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Cake', 'base_price' => '1.500',
        'status' => 'active', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
    ]);
    DB::table('pos_branch_product')->insert([
        'branch_id' => $branch->id, 'product_id' => $restricted, 'is_available' => true, 'stock_qty' => '3.000',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('pos_products')->where('id', $everywhere)->value('branch_scope'))->toBe('all')
        ->and(DB::table('pos_products')->where('id', $restricted)->value('branch_scope'))->toBe('selected')
        // The backfill writes the new column only.
        ->and((string) DB::table('pos_products')->where('id', $restricted)->value('updated_at'))->toBe('2026-09-01 08:00:00');
});

it('lets a delivery price be unlisted and leave its price to the product (NULL)', function (): void {
    expect(Schema::hasColumn('pos_product_delivery_prices', 'listed'))->toBeTrue();

    $company = Company::factory()->create();
    $product = p4Product($company->id, 'Burger', ['delivery_price' => '2.200']);
    $provider = (int) DB::table('pos_delivery_providers')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Talabat', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('pos_product_delivery_prices')->insert([
        'product_id' => $product, 'delivery_provider_id' => $provider, 'company_id' => $company->id, 'price' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $row = DB::table('pos_product_delivery_prices')->first();
    expect((bool) $row->listed)->toBeTrue()->and($row->price)->toBeNull();

    // Rolling back restores the price the NULL stood for (delivery price).
    $migration = require database_path('migrations/2026_10_03_100002_add_listed_to_pos_product_delivery_prices.php');
    $migration->down();
    expect((float) DB::table('pos_product_delivery_prices')->value('price'))->toBe(2.2)
        ->and(Schema::hasColumn('pos_product_delivery_prices', 'listed'))->toBeFalse();
    $migration->up();
});

it('stores combo slots and their options, one row per product in a slot', function (): void {
    // LAUNCH combo add-on — 2026_10_07_100002 retires the slot tables; its
    // down() re-creates them as they stood, so the P4 shape is still proven.
    (require database_path('migrations/2026_10_07_100002_retire_pos_combo_slots.php'))->down();
    expect(Schema::hasColumns('pos_combo_slots', [
        'id', 'uuid', 'company_id', 'combo_product_id', 'name', 'name_ar', 'min_choices', 'max_choices', 'sort_order', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('pos_combo_slot_options', [
            'id', 'company_id', 'slot_id', 'product_id', 'extra_price', 'is_default', 'sort_order', 'created_at', 'updated_at',
        ]))->toBeTrue();

    $company = Company::factory()->create();
    $combo = p4Product($company->id, 'Burger meal', ['product_type' => 'combo']);
    $burger = p4Product($company->id, 'Burger');
    $slot = p4Slot($company->id, $combo);
    $slotRow = DB::table('pos_combo_slots')->find($slot);
    expect((int) $slotRow->min_choices)->toBe(1)->and((int) $slotRow->max_choices)->toBe(1)->and((int) $slotRow->sort_order)->toBe(0);

    DB::table('pos_combo_slot_options')->insert([
        'company_id' => $company->id, 'slot_id' => $slot, 'product_id' => $burger, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $option = DB::table('pos_combo_slot_options')->first();
    expect((float) $option->extra_price)->toBe(0.0)->and((bool) $option->is_default)->toBeFalse();

    expect(fn () => DB::table('pos_combo_slot_options')->insert([
        'company_id' => $company->id, 'slot_id' => $slot, 'product_id' => $burger, 'extra_price' => '0.300', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // An offered product cannot be hard-deleted; the combo takes its slots and options with it.
    expect(fn () => DB::table('pos_products')->where('id', $burger)->delete())->toThrow(QueryException::class);
    DB::table('pos_products')->where('id', $combo)->delete();
    expect(DB::table('pos_combo_slots')->count())->toBe(0)->and(DB::table('pos_combo_slot_options')->count())->toBe(0);
});

it('keeps one sold-out row per branch and product', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $product = p4Product($company->id, 'Soup');
    $row = ['company_id' => $company->id, 'branch_id' => $branch->id, 'product_id' => $product, 'set_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_product_sold_out')->insert($row);

    expect(Schema::hasColumns('pos_product_sold_out', ['set_by_user_id', 'set_by_pos_staff_id', 'set_at']))->toBeTrue()
        ->and(fn () => DB::table('pos_product_sold_out')->insert($row))->toThrow(QueryException::class);
});

it('stamps orders exclusive by default and hangs combo children off their parent line', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $combo = p4Product($company->id, 'Meal', ['product_type' => 'combo']);
    $fries = p4Product($company->id, 'Fries');
    $order = p4Order($company->id, $branch->id);
    expect((bool) DB::table('pos_orders')->where('id', $order)->value('prices_include_tax'))->toBeFalse();

    $parent = p4Item($order, $combo);
    $child = p4Item($order, $fries, ['parent_order_item_id' => $parent, 'combo_slot_id' => 77, 'combo_extra_price' => '0.300',
        'unit_price_snapshot' => '0.000', 'line_total' => '0.000']);
    $row = DB::table('pos_order_items')->find($child);
    expect((int) $row->parent_order_item_id)->toBe($parent)
        ->and((int) $row->combo_slot_id)->toBe(77)
        ->and((float) $row->combo_extra_price)->toBe(0.3)
        ->and((float) DB::table('pos_order_items')->where('id', $parent)->value('combo_extra_price'))->toBe(0.0);

    DB::table('pos_order_items')->where('id', $parent)->delete();
    expect(DB::table('pos_order_items')->where('id', $child)->exists())->toBeFalse();
});

it('flags sold-out rows and combo children that cross a tenant', function (): void {
    // LAUNCH combo add-on — the slot checks retired with the slot tables
    // (2026_10_07_100002); the combo-line checks are in LaunchCombo.
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $branchA = Branch::factory()->for($a)->create();
    $branchB = Branch::factory()->for($b)->create();
    $comboA = p4Product($a->id, 'Meal', ['product_type' => 'combo']);
    $burgerA = p4Product($a->id, 'Burger');

    DB::table('pos_product_sold_out')->insert([
        'company_id' => $a->id, 'branch_id' => $branchA->id, 'product_id' => $burgerA, 'set_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $order = p4Order($a->id, $branchA->id);
    $parent = p4Item($order, $comboA);
    p4Item($order, $burgerA, ['parent_order_item_id' => $parent, 'unit_price_snapshot' => '0.000', 'line_total' => '0.000']);

    $run = fn (): array => app(TenantIntegrityChecks::class)->run();
    foreach (['sold_out_company', 'combo_child_order'] as $check) {
        expect($run()[$check]['count'])->toBe(0, $check);
    }
    expect($run())->not->toHaveKeys(['combo_slot_company', 'combo_option_company', 'combo_option_type']);

    $badSoldOut = (int) DB::table('pos_product_sold_out')->insertGetId([
        'company_id' => $a->id, 'branch_id' => $branchB->id, 'product_id' => $burgerA, 'set_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherOrder = p4Order($a->id, $branchA->id);
    $strayChild = p4Item($otherOrder, $burgerA, ['parent_order_item_id' => $parent, 'unit_price_snapshot' => '0.000', 'line_total' => '0.000']);

    $results = $run();
    expect($results['sold_out_company'])->toMatchArray(['count' => 1, 'sample_ids' => [$badSoldOut], 'classification' => 'violation'])
        ->and($results['combo_child_order'])->toMatchArray(['count' => 1, 'sample_ids' => [$strayChild]]);
});
