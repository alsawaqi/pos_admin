<?php

declare(strict_types=1);

/*
 * LAUNCH-P2 P2-1 / P2-6 — the admin payment-reversal engine (the twin of
 * pos_api's ConsumeInventoryAction) restores ingredients at the ledger's new
 * precision: quantities to 4 decimals (0.3 g of a kg ingredient), the frozen
 * per-base-unit cost to 6 decimals, dated at the sale time.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Support\Reversals\ConsumeInventoryAction;
use App\Support\Reversals\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function p2ReversalFixture(array $orderOverrides = []): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $ingredient = fn (string $name, string $unit, string $cost): int => (int) DB::table('pos_ingredients')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => $name, 'unit' => $unit,
        'default_unit_cost' => $cost, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $saffron = $ingredient('Saffron', 'kg', '120.000');
    $flour = $ingredient('Flour', 'g', '0.00035');
    $orderId = (int) DB::table('pos_orders')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'source' => 'pos', 'status' => 'paid', 'subtotal' => '1.000', 'tax_total' => '0.000',
        'grand_total' => '1.000', 'opened_at' => '2026-10-01 09:00:00', 'closed_at' => '2026-10-01 09:05:00',
        'created_at' => now(), 'updated_at' => now(),
    ], $orderOverrides));
    DB::table('pos_order_items')->insert([
        'order_id' => $orderId, 'product_name_snapshot' => 'Saffron bun', 'qty' => '1.000',
        'unit_price_snapshot' => '1.000', 'line_total' => '1.000',
        'recipe_snapshot_json' => json_encode([
            ['ingredient_id' => $saffron, 'qty' => 0.0003, 'unit' => 'kg', 'unit_cost' => 120.0],
            ['ingredient_id' => $flour, 'qty' => 80.0, 'unit' => 'g', 'unit_cost' => 0.00035],
        ]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['branch' => $branch, 'order' => Order::query()->findOrFail($orderId), 'saffron' => $saffron, 'flour' => $flour];
}

it('restores 0.3 g of a kg ingredient and keeps a 6-decimal unit cost', function (): void {
    $f = p2ReversalFixture();

    expect((new ConsumeInventoryAction)->reverse($f['order']))->toBe(2);

    $saffron = DB::table('pos_stock_movements')->where('ingredient_id', $f['saffron'])->sole();
    expect((float) $saffron->quantity)->toBe(0.0003)
        ->and((float) $saffron->unit_cost_at_time)->toBe(120.0);
    $flour = DB::table('pos_stock_movements')->where('ingredient_id', $f['flour'])->sole();
    expect((float) $flour->quantity)->toBe(80.0)
        ->and((float) $flour->unit_cost_at_time)->toBe(0.00035);

    // The balance moved by exactly the ledger amount.
    expect((float) DB::table('pos_branch_stock')->where('ingredient_id', $f['saffron'])->value('quantity'))->toBe(0.0003);
    // Dated at the sale, not at the reversal.
    expect((string) $saffron->occurred_at)->toStartWith('2026-10-01 09:05:00');
});

it('dates a pending delivery reversal at the hand-off, not at the reversal moment', function (): void {
    $f = p2ReversalFixture(['status' => 'pending_verification', 'closed_at' => null, 'delivery_punched_at' => '2026-10-01 11:30:00']);

    (new ConsumeInventoryAction)->reverse($f['order']);

    $movement = DB::table('pos_stock_movements')->where('ingredient_id', $f['flour'])->sole();
    expect((string) $movement->occurred_at)->toStartWith('2026-10-01 11:30:00');
});
