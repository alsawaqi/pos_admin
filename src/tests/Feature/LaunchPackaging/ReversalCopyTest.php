<?php

declare(strict_types=1);

/*
 * LAUNCH packaging add-on §3 Part A / 3 — the admin payment-reversal engine
 * (the twin of pos_api's ConsumeInventoryAction) restores exactly what pos_api
 * restores, on the fixture shared with pos_api
 * (tests/Fixtures/launch-packaging-reversal.json, byte-identical in both
 * repos; pos_api runs it in tests/Feature/LaunchPackaging/ReversalFixtureTest):
 *  - a stamped to-go order restores only its to-go lines (recipe, physical
 *    items, add-on stock lines, the legacy add-on ingredient) and its frozen
 *    packaging, once;
 *  - an order stocked before the release (no stamp, a legacy line read from
 *    live components now ticked "not dine in") restores every line.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Support\Reversals\ConsumeInventoryAction;
use App\Support\Reversals\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pkFixture(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/launch-packaging-reversal.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array{order: Order, branch: Branch, ingredients: array<string, int>, products: array<string, int>} */
function pkLoad(string $name): array
{
    $fixture = pkFixture();
    $t = ['created_at' => now(), 'updated_at' => now()];
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $ingredients = [];
    foreach ($fixture['ingredients'] as $key => $row) {
        $ingredients[$key] = (int) DB::table('pos_ingredients')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
            'name' => $key, 'unit' => $row['unit'], 'default_unit_cost' => $row['cost']] + $t);
        DB::table('pos_branch_stock')->insert(['branch_id' => $branch->id, 'ingredient_id' => $ingredients[$key], 'quantity' => '1000'] + $t);
    }
    $products = [];
    foreach ($fixture['products'] as $key => $row) {
        $products[$key] = (int) DB::table('pos_products')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
            'name' => $key, 'base_price' => '1.000', 'stock_mode' => $row['stock_mode'], 'status' => 'active'] + $t);
        if ($row['stock_mode'] === 'unit') {
            DB::table('pos_branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $products[$key], 'is_available' => true,
                'stock_qty' => '100.000'] + $t);
        }
    }
    foreach ($fixture['live_components'] as $parent => $lines) {
        foreach ($lines as $line) {
            DB::table('pos_product_components')->insert(['product_id' => $products[$parent], 'component_product_id' => $products[$line['product']],
                'quantity' => $line['qty'], 'order_types' => $line['order_types'] ?? 15] + $t);
        }
    }
    $resolve = static function (?array $lines) use ($ingredients, $products): ?array {
        return $lines === null ? null : array_map(static function (array $line) use ($ingredients, $products): array {
            if (isset($line['ingredient'])) {
                $line = ['ingredient_id' => $ingredients[$line['ingredient']]] + array_diff_key($line, ['ingredient' => 1]);
            }
            if (isset($line['product'])) {
                $line = ['product_id' => $products[$line['product']]] + array_diff_key($line, ['product' => 1]);
            }

            return $line;
        }, $lines);
    };

    $case = $fixture['cases'][$name];
    $packaging = $case['order']['packaging'];
    $orderId = (int) DB::table('pos_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'branch_id' => $branch->id, 'order_type' => $case['order']['order_type'], 'source' => 'pos', 'status' => 'paid',
        'subtotal' => '3.000', 'tax_total' => '0.000', 'grand_total' => '3.000', 'opened_at' => '2026-10-06 08:00:00',
        'closed_at' => '2026-10-06 08:05:00', 'stock_order_type' => $case['order']['stock_order_type'],
        'packaging_snapshot_json' => $packaging === null ? null
            : json_encode(['order_type' => $packaging['order_type'], 'lines' => $resolve($packaging['lines'])]),
    ] + $t);
    foreach ($case['items'] as $item) {
        $itemId = (int) DB::table('pos_order_items')->insertGetId(['order_id' => $orderId, 'product_id' => $products[$item['product']],
            'product_name_snapshot' => $item['product'], 'qty' => $item['qty'], 'unit_price_snapshot' => '1.500', 'line_total' => '3.000',
            'recipe_snapshot_json' => json_encode($resolve($item['recipe'])),
            'component_snapshot_json' => $item['components'] === null ? null : json_encode($resolve($item['components'])),
        ] + $t);
        foreach ($item['addons'] as $addon) {
            DB::table('pos_order_item_addons')->insert(['order_item_id' => $itemId, 'add_on_name_snapshot' => 'option',
                'price_delta_snapshot' => '0.000',
                'consumption_snapshot_json' => isset($addon['consumption']) ? json_encode($resolve($addon['consumption'])) : null,
                'ingredient_snapshot_json' => isset($addon['trio']) ? json_encode($resolve([$addon['trio']])[0]) : null,
                // Fix order PK-A1 (L6) — a product-as-add-on with ticked recipe / components.
                'linked_product_id' => isset($addon['linked']) ? $products[$addon['linked']['product']] : null,
                'product_snapshot_json' => isset($addon['linked']) ? json_encode(['product_id' => $products[$addon['linked']['product']],
                    'stock_mode' => $addon['linked']['stock_mode'], 'recipe' => $resolve($addon['linked']['recipe']),
                    'components' => $resolve($addon['linked']['components'])]) : null,
            ] + $t);
        }
    }

    return ['order' => Order::query()->findOrFail($orderId), 'branch' => $branch, 'ingredients' => $ingredients, 'products' => $products];
}

/** @return array{ingredients: array<string, float>, products: array<string, float>} */
function pkRestored(array $loaded, array $expected): array
{
    $restored = ['ingredients' => [], 'products' => []];
    foreach ($loaded['ingredients'] as $key => $id) {
        $restored['ingredients'][$key] = round((float) DB::table('pos_branch_stock')->where('ingredient_id', $id)->value('quantity') - 1000, 4);
    }
    foreach (array_intersect_key($loaded['products'], $expected['products']) as $key => $id) {
        $restored['products'][$key] = round((float) DB::table('pos_branch_product')->where('product_id', $id)->value('stock_qty') - 100, 3);
    }

    return $restored;
}

it('restores exactly what pos_api restores for the shared fixture', function (string $name): void {
    $loaded = pkLoad($name);
    $expected = pkFixture()['cases'][$name]['expected_restore'];

    DB::transaction(fn () => (new ConsumeInventoryAction)->reverse($loaded['order']));

    expect(pkRestored($loaded, $expected))->toEqual($expected);
})->with(fn (): array => array_keys(pkFixture()['cases']));

it('writes the packaging back once with the pos_api note, as sale consumption', function (): void {
    $loaded = pkLoad('stamped_to_go');

    DB::transaction(fn () => (new ConsumeInventoryAction)->reverse($loaded['order']));

    $napkin = DB::table('pos_stock_movements')->where('ingredient_id', $loaded['ingredients']['napkin'])->sole();
    $bag = DB::table('pos_product_stock_movements')->where('product_id', $loaded['products']['bag'])->sole();
    expect([$napkin->movement_type, $napkin->note, (float) $napkin->quantity])->toBe(['sale_consumption', 'order packaging (to_go)', 3.0])
        ->and([$bag->movement_type, $bag->note, (float) $bag->quantity])->toBe(['sale_consumption', 'order packaging (to_go)', 1.0]);
});
