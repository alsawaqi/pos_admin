<?php

declare(strict_types=1);

/*
 * LAUNCH review add-on — the data contract (LAUNCH-REVIEW_WORK_ORDER.md §3.1;
 * pos_admin owns every migration, pos_api and pos_merchant mirror them):
 *  01 containers nest, and the same word may have several sizes;
 *  02 pos_ingredients.count_container_id and sku;
 *  03 the count containers become container rows (data, idempotent);
 *  04 a SKU for every live ingredient (data, idempotent);
 *  05 physical-item packs; 06 barcodes; 07 the stock breakdown by container;
 *  08 the container columns on stock documents;
 *  09 the main slot, limited-time dates and cooking time;
 *  10 add-on group kinds and the Remove option's ingredient;
 *  and pos:check-tenant-integrity covers every new relation.
 *
 * The suites run on SQLite; the Postgres-only CHECK constraints are proven by
 * the live-copy rehearsal's must-fail SQL (rehearse-rv.sh).
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

const RV_STAMP = '2026-09-01 08:00:00';

function rvMigration(string $name): object
{
    return require database_path('migrations/'.$name.'.php');
}

function rvIngredient(int $companyId, string $name, array $extra = []): int
{
    return (int) DB::table('pos_ingredients')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'unit' => 'ml',
        'created_at' => RV_STAMP, 'updated_at' => RV_STAMP,
    ]);
}

function rvContainer(int $companyId, int $ingredientId, string $name, string $factor, array $extra = []): int
{
    return (int) DB::table('pos_ingredient_units')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'ingredient_id' => $ingredientId, 'name' => $name,
        'factor' => $factor, 'created_at' => RV_STAMP, 'updated_at' => RV_STAMP,
    ]);
}

function rvProduct(int $companyId, string $name, array $extra = []): int
{
    return (int) DB::table('pos_products')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'base_price' => '1.000',
        'stock_mode' => 'untracked', 'status' => 'active', 'created_at' => RV_STAMP, 'updated_at' => RV_STAMP,
    ]);
}

function rvBarcode(int $companyId, string $code, array $extra): int
{
    return (int) DB::table('pos_item_barcodes')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'barcode' => $code, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function rvChecks(): array
{
    return app(TenantIntegrityChecks::class)->run();
}

it('lets an item have several containers of one word with different sizes, and nested containers (01)', function (): void {
    expect(Schema::hasColumns('pos_ingredient_units', ['contains_unit_id', 'contains_quantity']))->toBeTrue();

    $company = Company::factory()->create();
    $milk = rvIngredient($company->id, 'Milk');
    $big = rvContainer($company->id, $milk, 'bottle', '1500');
    rvContainer($company->id, $milk, 'bottle', '500');
    $litre = rvContainer($company->id, $milk, 'bottle', '1000');
    $crate = rvContainer($company->id, $milk, 'crate', '12000', ['contains_unit_id' => $litre, 'contains_quantity' => '12']);

    expect(DB::table('pos_ingredient_units')->where('ingredient_id', $milk)->where('name', 'bottle')->count())->toBe(3)
        ->and((int) DB::table('pos_ingredient_units')->where('id', $crate)->value('contains_unit_id'))->toBe($litre)
        ->and((float) DB::table('pos_ingredient_units')->where('id', $crate)->value('contains_quantity'))->toBe(12.0);

    // The same live size twice is refused, whatever the case of the name.
    expect(fn () => rvContainer($company->id, $milk, 'Bottle', '1500'))->toThrow(QueryException::class);

    // A soft-deleted container no longer holds its size.
    DB::table('pos_ingredient_units')->where('id', $big)->update(['deleted_at' => now()]);
    rvContainer($company->id, $milk, 'BOTTLE', '1500');

    // A crate of the same word and size but holding something else is another container.
    rvContainer($company->id, $milk, 'crate', '12000');

    // A held container cannot be deleted on its own.
    expect(fn () => DB::table('pos_ingredient_units')->where('id', $litre)->delete())->toThrow(QueryException::class);
});

it('refuses to roll back the index swap once two containers of an item share a name (01)', function (): void {
    $company = Company::factory()->create();
    $milk = rvIngredient($company->id, 'Milk');
    rvContainer($company->id, $milk, 'bottle', '1500');
    rvContainer($company->id, $milk, 'bottle', '500');

    expect(fn () => rvMigration('2026_10_06_100001_add_nesting_to_pos_ingredient_units')->down())
        ->toThrow(RuntimeException::class, 'two containers with the same name');
    expect(Schema::hasColumn('pos_ingredient_units', 'contains_unit_id'))->toBeTrue();
});

it('adds the count container link and a per-company, case-insensitive SKU among live ingredients (02)', function (): void {
    expect(Schema::hasColumns('pos_ingredients', ['count_container_id', 'sku']))->toBeTrue();

    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $milk = rvIngredient($a->id, 'Milk', ['sku' => 'MLK-1']);
    expect(DB::table('pos_ingredients')->where('id', $milk)->value('count_container_id'))->toBeNull();

    expect(fn () => rvIngredient($a->id, 'Cream', ['sku' => 'mlk-1']))->toThrow(QueryException::class);
    rvIngredient($b->id, 'Milk', ['sku' => 'MLK-1']);
    rvIngredient($a->id, 'Sugar');
    rvIngredient($a->id, 'Salt');

    DB::table('pos_ingredients')->where('id', $milk)->update(['deleted_at' => now()]);
    rvIngredient($a->id, 'Oat milk', ['sku' => 'mlk-1']);

    // Deleting the count container empties the link.
    $bottle = rvContainer($a->id, $milk, 'bottle', '1000');
    DB::table('pos_ingredients')->where('id', $milk)->update(['count_container_id' => $bottle]);
    DB::table('pos_ingredient_units')->where('id', $bottle)->delete();
    expect(DB::table('pos_ingredients')->where('id', $milk)->value('count_container_id'))->toBeNull();
});

it('turns each count container into a container row, linking a matching one, without touching anything else (03)', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $piece = ['piece_unit_label' => 'bottle', 'piece_unit_label_ar' => 'قارورة', 'units_per_piece' => '1500'];

    $linked = rvIngredient($company->id, 'Milk', ['piece_unit_label' => 'Bottle', 'units_per_piece' => '1000']);
    $existing = rvContainer($company->id, $linked, 'bottle', '1000');
    $sized = rvIngredient($company->id, 'Cream', $piece);
    $otherSize = rvContainer($company->id, $sized, 'bottle', '500', ['sort_order' => 3]);
    $deletedMatch = rvIngredient($company->id, 'Juice', $piece);
    rvContainer($company->id, $deletedMatch, 'bottle', '1500', ['deleted_at' => now()]);
    $plain = rvIngredient($company->id, 'Sugar');
    $blank = rvIngredient($company->id, 'Salt', ['piece_unit_label' => '  ', 'units_per_piece' => '1000']);
    $noRatio = rvIngredient($company->id, 'Pepper', ['piece_unit_label' => 'jar', 'units_per_piece' => null]);
    $foreign = rvIngredient($other->id, 'Milk', $piece + ['status' => 'inactive']);
    $before = DB::table('pos_ingredients')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $unitsBefore = DB::table('pos_ingredient_units')->count();

    $migration = rvMigration('2026_10_06_100003_merge_count_containers_into_pos_ingredient_units');
    $migration->up();

    $link = fn (int $id) => DB::table('pos_ingredients')->where('id', $id)->value('count_container_id');
    expect((int) $link($linked))->toBe($existing)
        ->and($link($plain))->toBeNull()
        ->and($link($blank))->toBeNull()
        ->and($link($noRatio))->toBeNull();

    // A new "bottle 1.5 l" next to the item's "bottle 500 ml", after its other containers.
    $new = DB::table('pos_ingredient_units')->find($link($sized));
    expect($new->id)->not->toBe($otherSize)
        ->and($new->name)->toBe('bottle')
        ->and($new->name_ar)->toBe('قارورة')
        ->and((float) $new->factor)->toBe(1500.0)
        ->and((int) $new->company_id)->toBe($company->id)
        ->and((int) $new->sort_order)->toBe(4)
        ->and($new->contains_unit_id)->toBeNull()
        ->and($new->uuid)->toBe($migration::insertedUuid(DB::table('pos_ingredients')->where('id', $sized)->value('uuid')));
    // A soft-deleted container of the same size is not linked: a live one is made.
    expect(DB::table('pos_ingredient_units')->where('id', $link($deletedMatch))->value('deleted_at'))->toBeNull();
    expect((int) DB::table('pos_ingredient_units')->where('id', $link($foreign))->value('company_id'))->toBe($other->id);

    // Count containers merged = ingredients with a label.
    expect(DB::table('pos_ingredients')->whereNotNull('count_container_id')->count())->toBe(4)
        ->and(DB::table('pos_ingredient_units')->count())->toBe($unitsBefore + 3);

    // Nothing else changed: updated_at, the piece_* mirror and every other value.
    $after = DB::table('pos_ingredients')->orderBy('id')->get()->map(fn ($row) => array_diff_key((array) $row, ['count_container_id' => 1]))->all();
    expect($after)->toBe(array_map(fn ($row) => array_diff_key($row, ['count_container_id' => 1]), $before));
    expect(DB::table('pos_ingredient_units')->where('id', $existing)->value('updated_at'))->toBe(RV_STAMP);

    // A re-run is a no-op.
    $snapshot = DB::table('pos_ingredient_units')->orderBy('id')->get()->all();
    $migration->up();
    expect(DB::table('pos_ingredient_units')->orderBy('id')->get()->all())->toEqual($snapshot);

    // down() removes exactly the rows it inserted and keeps the merchant's container.
    $migration->down();
    expect(DB::table('pos_ingredient_units')->count())->toBe($unitsBefore)
        ->and(DB::table('pos_ingredient_units')->where('id', $existing)->exists())->toBeTrue()
        ->and($link($sized))->toBeNull()
        ->and((int) $link($linked))->toBe($existing);
});

it('gives every live ingredient the next free ING-#### of its company, skipping used codes (04)', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $first = rvIngredient($a->id, 'Milk');
    rvIngredient($a->id, 'Cream', ['sku' => 'ing-0002']);
    rvProduct($a->id, 'Cups', ['sku' => 'ING-0003', 'deleted_at' => now()]);
    $second = rvIngredient($a->id, 'Sugar');
    $third = rvIngredient($a->id, 'Salt');
    $deleted = rvIngredient($a->id, 'Old', ['deleted_at' => now()]);
    $otherCompany = rvIngredient($b->id, 'Milk');
    rvProduct($b->id, 'Lids', ['sku' => 'ING-0002']);

    $migration = rvMigration('2026_10_06_100004_backfill_ingredient_skus');
    $migration->up();

    $sku = fn (int $id) => DB::table('pos_ingredients')->where('id', $id)->value('sku');
    expect($sku($first))->toBe('ING-0001')
        ->and($sku($second))->toBe('ING-0004')
        ->and($sku($third))->toBe('ING-0005')
        ->and($sku($deleted))->toBeNull()
        ->and($sku($otherCompany))->toBe('ING-0001')
        ->and(DB::table('pos_ingredients')->where('id', $first)->value('updated_at'))->toBe(RV_STAMP);

    $migration->up();
    expect(DB::table('pos_ingredients')->whereNotNull('sku')->count())->toBe(5)
        ->and($sku($third))->toBe('ING-0005')
        ->and($migration::code(12345))->toBe('ING-12345');
});

it('stores physical-item packs, one live size per name (05)', function (): void {
    expect(Schema::hasColumns('pos_product_packs', [
        'id', 'uuid', 'company_id', 'product_id', 'name', 'name_ar', 'pieces', 'contains_pack_id', 'contains_quantity', 'sort_order',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();

    $company = Company::factory()->create();
    $cups = rvProduct($company->id, 'Cups', ['is_internal' => true]);
    $pack = fn (string $name, string $pieces, array $extra = []) => (int) DB::table('pos_product_packs')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'product_id' => $cups, 'name' => $name, 'pieces' => $pieces,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $box = $pack('box', '50');
    $pack('box', '100');
    $carton = $pack('carton', '500', ['contains_pack_id' => $box, 'contains_quantity' => '10']);
    expect((int) DB::table('pos_product_packs')->where('id', $carton)->value('contains_pack_id'))->toBe($box)
        ->and(fn () => $pack('BOX', '50'))->toThrow(QueryException::class);
    DB::table('pos_product_packs')->where('id', $box)->update(['deleted_at' => now()]);
    $pack('box', '50');
    expect(fn () => DB::table('pos_products')->where('id', $cups)->delete())->toThrow(QueryException::class);
});

it('keeps one live barcode per company code (06)', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $milk = rvIngredient($a->id, 'Milk');
    $bottle = rvContainer($a->id, $milk, 'bottle', '1000');
    $first = rvBarcode($a->id, '0062911', ['ingredient_id' => $milk, 'container_id' => $bottle, 'label' => 'Al Safi']);
    rvBarcode($a->id, '0062912', ['ingredient_id' => $milk, 'container_id' => $bottle]);
    rvBarcode($b->id, '0062911', ['product_id' => rvProduct($b->id, 'Cups', ['is_internal' => true])]);

    expect(DB::table('pos_item_barcodes')->where('id', $first)->value('barcode'))->toBe('0062911')
        ->and(fn () => rvBarcode($a->id, '0062911', ['ingredient_id' => $milk]))->toThrow(QueryException::class);
    DB::table('pos_item_barcodes')->where('id', $first)->update(['deleted_at' => now()]);
    rvBarcode($a->id, '0062911', ['ingredient_id' => $milk]);

    // A barcode goes with its container.
    DB::table('pos_ingredient_units')->where('id', $bottle)->delete();
    expect(DB::table('pos_item_barcodes')->where('barcode', '0062912')->exists())->toBeFalse();
});

it('keeps one breakdown row per place, item and container, and leaves existing balances empty (07)', function (): void {
    expect(Schema::hasColumns('pos_stock_container_movements', [
        'company_id', 'branch_id', 'ingredient_id', 'container_id', 'delta_pieces', 'pieces_after', 'reason', 'stock_movement_id',
        'reference_type', 'reference_id', 'recorded_by_user_id', 'recorded_by_pos_staff_id', 'occurred_at', 'created_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('pos_branch_stock', ['containers_counted_at', 'containers_total_count_at']))->toBeTrue()
        ->and(Schema::hasColumn('pos_ingredient_stock', 'containers_counted_at'))->toBeTrue();

    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $milk = rvIngredient($company->id, 'Milk');
    $bottle = rvContainer($company->id, $milk, 'bottle', '1000');
    DB::table('pos_branch_stock')->insert(['branch_id' => $branch->id, 'ingredient_id' => $milk, 'quantity' => '3000',
        'created_at' => now(), 'updated_at' => now()]);
    $stock = DB::table('pos_branch_stock')->first();
    expect($stock->containers_counted_at)->toBeNull()->and($stock->containers_total_count_at)->toBeNull();

    $balance = fn (?int $branchId) => DB::table('pos_stock_container_balances')->insertGetId([
        'company_id' => $company->id, 'branch_id' => $branchId, 'ingredient_id' => $milk, 'container_id' => $bottle,
        'pieces' => '3', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $balance($branch->id);
    $balance(null);
    expect(fn () => $balance($branch->id))->toThrow(QueryException::class)
        ->and(fn () => $balance(null))->toThrow(QueryException::class)
        ->and((float) DB::table('pos_stock_container_balances')->whereNull('branch_id')->value('pieces'))->toBe(3.0);
});

it('adds the container columns to stock documents, empty for existing rows (08)', function (): void {
    expect(Schema::hasColumns('pos_purchase_receipt_lines', ['container_id', 'pack_id', 'container_label', 'container_factor', 'pieces']))->toBeTrue()
        ->and(Schema::hasColumns('pos_waste_records', ['container_id', 'pieces', 'container_label']))->toBeTrue()
        ->and(Schema::hasColumns('pos_restock_request_lines', ['container_id', 'pieces', 'container_label']))->toBeTrue()
        ->and(Schema::hasColumns('pos_branch_transfer_line_containers', [
            'id', 'branch_transfer_line_id', 'company_id', 'container_id', 'container_label', 'container_factor', 'pieces',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('pos_stock_count_line_containers', [
            'id', 'stock_count_line_id', 'company_id', 'container_id', 'container_label', 'container_factor', 'pieces',
        ]))->toBeTrue();

    $company = Company::factory()->create();
    $from = Branch::factory()->create(['company_id' => $company->id]);
    $to = Branch::factory()->create(['company_id' => $company->id]);
    $milk = rvIngredient($company->id, 'Milk');
    $bottle = rvContainer($company->id, $milk, 'bottle', '1000');
    $transfer = DB::table('pos_branch_transfers')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'created_at' => now(), 'updated_at' => now()]);
    $line = DB::table('pos_branch_transfer_lines')->insertGetId(['branch_transfer_id' => $transfer, 'ingredient_id' => $milk,
        'quantity' => '2500', 'unit_at_set' => 'ml', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_branch_transfer_line_containers')->insert(['branch_transfer_line_id' => $line, 'company_id' => $company->id,
        'container_id' => $bottle, 'container_label' => 'bottle 1 l', 'container_factor' => '1000', 'pieces' => '3',
        'created_at' => now(), 'updated_at' => now()]);

    // The snapshot stays when the container goes; the rows go with their line.
    DB::table('pos_ingredient_units')->where('id', $bottle)->delete();
    expect(DB::table('pos_branch_transfer_line_containers')->value('container_id'))->toBeNull()
        ->and(DB::table('pos_branch_transfer_line_containers')->value('container_label'))->toBe('bottle 1 l');
    DB::table('pos_branch_transfer_lines')->where('id', $line)->delete();
    expect(DB::table('pos_branch_transfer_line_containers')->count())->toBe(0);
});

it('adds the main slot (one per combo), limited-time dates and cooking time, all unset for existing rows (09)', function (): void {
    // LAUNCH combo add-on — the slot tables are retired (2026_10_07_100002);
    // its down() re-creates them as they stood after 09.
    (require database_path('migrations/2026_10_07_100002_retire_pos_combo_slots.php'))->down();
    $company = Company::factory()->create();
    $combo = rvProduct($company->id, 'Burger meal', ['product_type' => 'combo']);
    $slot = fn (string $name, bool $main) => (int) DB::table('pos_combo_slots')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'combo_product_id' => $combo, 'name' => $name,
        'is_main' => $main, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $existing = (int) DB::table('pos_combo_slots')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'combo_product_id' => $combo, 'name' => 'Side', 'created_at' => now(), 'updated_at' => now()]);
    expect((bool) DB::table('pos_combo_slots')->where('id', $existing)->value('is_main'))->toBeFalse();

    $slot('Burger', true);
    $slot('Drink', false);
    expect(fn () => $slot('Dessert', true))->toThrow(QueryException::class);

    // Another combo has its own main.
    $other = rvProduct($company->id, 'Chicken meal', ['product_type' => 'combo']);
    DB::table('pos_combo_slots')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'combo_product_id' => $other,
        'name' => 'Chicken', 'is_main' => true, 'created_at' => now(), 'updated_at' => now()]);
    expect(DB::table('pos_combo_slots')->where('is_main', true)->count())->toBe(2);
});

it('stores dates and cooking time on products and the cooking snapshot on order lines (09)', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $burger = rvProduct($company->id, 'Burger');
    $row = DB::table('pos_products')->find($burger);
    expect($row->on_sale_from)->toBeNull()->and($row->on_sale_until)->toBeNull()->and($row->cooking_minutes)->toBeNull();

    DB::table('pos_products')->where('id', $burger)->update(['on_sale_from' => '2026-11-01', 'on_sale_until' => '2026-11-30', 'cooking_minutes' => 12]);
    $row = DB::table('pos_products')->find($burger);
    expect(substr((string) $row->on_sale_from, 0, 10))->toBe('2026-11-01')
        ->and((int) $row->cooking_minutes)->toBe(12)
        ->and($row->updated_at)->toBe(RV_STAMP);

    $order = DB::table('pos_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'source' => 'pos', 'status' => 'paid', 'subtotal' => '2.000', 'tax_total' => '0.000',
        'grand_total' => '2.000', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $item = DB::table('pos_order_items')->insertGetId(['order_id' => $order, 'product_id' => $burger, 'product_name_snapshot' => 'Burger',
        'qty' => '1.000', 'unit_price_snapshot' => '2.000', 'line_total' => '2.000', 'created_at' => now(), 'updated_at' => now()]);
    expect(DB::table('pos_order_items')->where('id', $item)->value('cooking_minutes'))->toBeNull();
});

it('adds the add-on group kind (extras by default) and the Remove option\'s ingredient (10)', function (): void {
    $company = Company::factory()->create();
    $burger = rvProduct($company->id, 'Burger');
    $ketchup = rvIngredient($company->id, 'Ketchup');
    $extras = (int) DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'name' => 'Extras', 'created_at' => now(), 'updated_at' => now()]);
    $remove = (int) DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'name' => 'Remove', 'kind' => 'remove', 'owner_product_id' => $burger, 'selection_mode' => 'multi',
        'created_at' => now(), 'updated_at' => now()]);
    $option = (int) DB::table('pos_addons')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'add_on_group_id' => $remove, 'name' => 'NO Ketchup', 'removes_ingredient_id' => $ketchup, 'created_at' => now(), 'updated_at' => now()]);

    expect(DB::table('pos_addon_groups')->where('id', $extras)->value('kind'))->toBe('extras')
        ->and((int) DB::table('pos_addons')->where('id', $option)->value('removes_ingredient_id'))->toBe($ketchup);

    DB::table('pos_ingredients')->where('id', $ketchup)->delete();
    expect(DB::table('pos_addons')->where('id', $option)->value('removes_ingredient_id'))->toBeNull();
});

it('reports every new cross-row relation in pos:check-tenant-integrity', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $branchA = Branch::factory()->create(['company_id' => $a->id]);
    $branchB = Branch::factory()->create(['company_id' => $b->id]);
    $milk = rvIngredient($a->id, 'Milk');
    $cream = rvIngredient($a->id, 'Cream');
    $foreignIngredient = rvIngredient($b->id, 'Milk');
    $bottle = rvContainer($a->id, $milk, 'bottle', '1000');
    $creamBottle = rvContainer($a->id, $cream, 'bottle', '500');
    $cups = rvProduct($a->id, 'Cups', ['is_internal' => true]);
    $lids = rvProduct($a->id, 'Lids', ['is_internal' => true]);
    $burger = rvProduct($a->id, 'Burger');
    $pack = fn (int $companyId, int $productId, array $extra = []) => (int) DB::table('pos_product_packs')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'product_id' => $productId, 'name' => 'box', 'pieces' => '50',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $box = $pack($a->id, $cups);
    $lidBox = $pack($a->id, $lids);
    $group = fn (array $extra) => (int) DB::table('pos_addon_groups')->insertGetId($extra + ['uuid' => (string) Str::uuid(),
        'company_id' => $a->id, 'name' => 'G'.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
    $addon = fn (int $groupId, array $extra = []) => (int) DB::table('pos_addons')->insertGetId($extra + ['uuid' => (string) Str::uuid(),
        'company_id' => $a->id, 'add_on_group_id' => $groupId, 'name' => 'A'.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
    $balance = fn (string $table, array $row) => (int) DB::table($table)->insertGetId($row + ['created_at' => now()]
        + ($table === 'pos_stock_container_balances' ? ['updated_at' => now()] : ['delta_pieces' => '1', 'pieces_after' => '1', 'reason' => 'purchase']));

    // Clean rows of every kind.
    DB::table('pos_ingredients')->where('id', $milk)->update(['count_container_id' => $bottle]);
    rvContainer($a->id, $milk, 'crate', '12000', ['contains_unit_id' => $bottle, 'contains_quantity' => '12']);
    $pack($a->id, $cups, ['name' => 'carton', 'pieces' => '500', 'contains_pack_id' => $box, 'contains_quantity' => '10']);
    rvBarcode($a->id, '111', ['ingredient_id' => $milk, 'container_id' => $bottle]);
    rvBarcode($a->id, '112', ['product_id' => $cups, 'pack_id' => $box]);
    foreach (['pos_stock_container_balances', 'pos_stock_container_movements'] as $table) {
        $balance($table, ['company_id' => $a->id, 'branch_id' => $branchA->id, 'ingredient_id' => $milk, 'container_id' => $bottle]);
        $balance($table, ['company_id' => $a->id, 'branch_id' => null, 'ingredient_id' => $milk, 'container_id' => $bottle]);
    }
    $removeGroup = $group(['kind' => 'remove', 'owner_product_id' => $burger]);
    $addon($removeGroup, ['removes_ingredient_id' => $milk]);
    $addon($group(['kind' => 'instructions']));
    $addon($group([]), ['price_delta' => '0.300']);

    $names = ['ingredient_container_company', 'ingredient_container_contains_item', 'ingredient_count_container_item',
        'product_pack_company', 'product_pack_contains_item', 'barcode_item_company', 'container_balance_company',
        'container_movement_company', 'addon_removes_ingredient_company', 'addon_remove_group_unowned',
        'addon_remove_option_priced', 'addon_instruction_option_priced'];
    $clean = rvChecks();
    foreach ($names as $name) {
        expect($clean[$name])->toMatchArray(['count' => 0, 'classification' => 'violation']);
    }

    // One bad row per check.
    $bad = [
        'ingredient_container_company' => rvContainer($b->id, $milk, 'can', '330'),
        'ingredient_container_contains_item' => rvContainer($a->id, $milk, 'pack', '6000', ['contains_unit_id' => $creamBottle, 'contains_quantity' => '12']),
        'ingredient_count_container_item' => (function () use ($cream, $bottle) {
            DB::table('pos_ingredients')->where('id', $cream)->update(['count_container_id' => $bottle]);

            return $cream;
        })(),
        'product_pack_company' => $pack($b->id, $cups, ['name' => 'bag']),
        'product_pack_contains_item' => $pack($a->id, $cups, ['name' => 'crate', 'pieces' => '500', 'contains_pack_id' => $lidBox, 'contains_quantity' => '10']),
        'barcode_item_company' => rvBarcode($a->id, '113', ['ingredient_id' => $foreignIngredient]),
        'container_balance_company' => $balance('pos_stock_container_balances', ['company_id' => $a->id, 'branch_id' => $branchB->id,
            'ingredient_id' => $milk, 'container_id' => $bottle]),
        'container_movement_company' => $balance('pos_stock_container_movements', ['company_id' => $a->id, 'branch_id' => $branchA->id,
            'ingredient_id' => $milk, 'container_id' => $creamBottle]),
        'addon_removes_ingredient_company' => $addon($removeGroup, ['removes_ingredient_id' => $foreignIngredient]),
        'addon_remove_group_unowned' => $group(['kind' => 'remove']),
        'addon_remove_option_priced' => $addon($removeGroup, ['price_delta' => '0.100']),
        'addon_instruction_option_priced' => $addon($group(['kind' => 'instructions']), ['price_delta' => '0.100']),
    ];
    $results = rvChecks();
    foreach ($bad as $name => $id) {
        expect($results[$name])->toMatchArray(['count' => 1, 'sample_ids' => [$id], 'classification' => 'violation']);
    }

    // A barcode whose container belongs to another item, and a pack barcode of another product.
    DB::table('pos_item_barcodes')->where('id', $bad['barcode_item_company'])->delete();
    $wrongContainer = rvBarcode($a->id, '114', ['ingredient_id' => $cream, 'container_id' => $bottle]);
    $wrongPack = rvBarcode($a->id, '115', ['product_id' => $lids, 'pack_id' => $box]);
    expect(rvChecks()['barcode_item_company'])->toMatchArray(['count' => 2, 'sample_ids' => [$wrongContainer, $wrongPack]]);
});
