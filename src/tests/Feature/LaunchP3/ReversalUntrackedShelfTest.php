<?php

declare(strict_types=1);

/*
 * LAUNCH-P3 fix order 1, K7 — the admin payment-reversal engine (the twin of
 * pos_api's ConsumeInventoryAction) follows the shelf rule: only a unit
 * (bought-in) or cooked product moves its branch shelf count. A product
 * switched to untracked (or made-to-order) can keep a leftover stock_qty on
 * pos_branch_product; a reversal must never move it.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Support\Reversals\ConsumeInventoryAction;
use App\Support\Reversals\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** One paid order of 2 × a product whose branch shelf holds 5. */
function k7ShelfFixture(string $stockMode): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $productId = (int) DB::table('pos_products')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Water', 'base_price' => '0.500',
        'stock_mode' => $stockMode, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // The leftover count from the product's tracked past.
    DB::table('pos_branch_product')->insert([
        'branch_id' => $branch->id, 'product_id' => $productId, 'is_available' => true, 'stock_qty' => '5.000',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $orderId = (int) DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'source' => 'pos', 'status' => 'paid', 'subtotal' => '1.000', 'tax_total' => '0.000',
        'grand_total' => '1.000', 'opened_at' => '2026-10-01 09:00:00', 'closed_at' => '2026-10-01 09:05:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('pos_order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'product_name_snapshot' => 'Water', 'qty' => '2.000',
        'unit_price_snapshot' => '0.500', 'line_total' => '1.000',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['branch' => $branch, 'product' => $productId, 'order' => Order::query()->findOrFail($orderId)];
}

function k7Shelf(array $f): string
{
    return (string) DB::table('pos_branch_product')->where('branch_id', $f['branch']->id)->where('product_id', $f['product'])->value('stock_qty');
}

it('never moves the leftover shelf count of an untracked product on a reversal', function (string $mode): void {
    $f = k7ShelfFixture($mode);

    (new ConsumeInventoryAction)->reverse($f['order']);

    expect((float) k7Shelf($f))->toBe(5.0)
        ->and(DB::table('pos_product_stock_movements')->where('product_id', $f['product'])->count())->toBe(0);

    (new ConsumeInventoryAction)->consume($f['order']);

    expect((float) k7Shelf($f))->toBe(5.0)
        ->and(DB::table('pos_product_stock_movements')->where('product_id', $f['product'])->count())->toBe(0);
})->with(['untracked', 'ingredient']);

it('still moves the shelf count of a bought-in or cooked product', function (string $mode): void {
    $f = k7ShelfFixture($mode);

    (new ConsumeInventoryAction)->reverse($f['order']);

    expect((float) k7Shelf($f))->toBe(7.0);
    $row = DB::table('pos_product_stock_movements')->where('product_id', $f['product'])->sole();
    expect((float) $row->quantity)->toBe(2.0);
})->with(['unit', 'cooked']);
