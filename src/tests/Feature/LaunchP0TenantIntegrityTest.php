<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Services\TenantIntegrityChecks;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('W11 records clean and failed runs without changing business rows and exposes them only to audit readers', function () {
    Log::spy();
    $this->artisan('pos:check-tenant-integrity')->assertSuccessful();
    expect(DB::table('pos_tenant_integrity_runs')->latest('id')->value('status'))->toBe('clean');
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $b->id]);
    $order = DB::table('pos_orders')->insertGetId([
        'uuid' => Str::uuid(), 'company_id' => $a->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'source' => 'main_pos', 'status' => 'paid',
        'subtotal' => 1, 'grand_total' => 1, 'opened_at' => now(),
    ]);
    $before = DB::table('pos_orders')->find($order);
    $this->artisan('pos:check-tenant-integrity')->assertFailed();
    expect(DB::table('pos_orders')->find($order))->toEqual($before);
    $this->seed(PlatformRoleSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole(PlatformRole::SuperAdmin->value);
    $this->actingAs($admin)->getJson('/admin/api/v1/dashboard/summary')
        ->assertOk()->assertJsonPath('data.tenant_integrity.status', 'violations')
        ->assertJsonPath('data.tenant_integrity.checks.order_branch.count', 1);
    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->getJson('/admin/api/v1/dashboard/summary')->assertOk()->assertJsonMissingPath('data.tenant_integrity');
    Log::shouldHaveReceived('log')->with('error', 'POS tenant integrity check', Mockery::type('array'))->once();
});

it('W11 detects every order, money and stock tenant mismatch using SQL', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $b->id]);
    $device = Device::factory()->create(['company_id' => $b->id, 'branch_id' => $branch->id]);
    $customer = DB::table('pos_customers')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $b->id, 'name' => 'Synthetic', 'phone' => '90000001']);
    $floor = DB::table('pos_floors')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $b->id, 'branch_id' => $branch->id, 'name' => 'Test']);
    $table = DB::table('pos_tables')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $b->id, 'floor_id' => $floor, 'label' => 'T1', 'qr_token' => Str::random(48)]);
    $order = DB::table('pos_orders')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $a->id, 'branch_id' => $branch->id, 'device_id' => $device->id,
        'customer_id' => $customer, 'table_id' => $table, 'order_type' => 'quick', 'source' => 'main_pos', 'status' => 'paid', 'subtotal' => 1, 'grand_total' => 1, 'opened_at' => now()]);
    $payment = DB::table('pos_payments')->insertGetId(['uuid' => Str::uuid(), 'order_id' => $order, 'method' => 'card', 'amount' => 1]);
    $payout = DB::table('pos_payouts')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $b->id, 'period_from' => now(), 'period_to' => now()]);
    $invoice = DB::table('pos_commission_invoices')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $b->id, 'period_from' => now(), 'period_to' => now()]);
    $settlement = DB::table('pos_commission_settlements')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $b->id]);
    DB::table('pos_sale_commissions')->insert(['uuid' => Str::uuid(), 'company_id' => $b->id, 'branch_id' => $branch->id, 'device_id' => $device->id, 'order_id' => $order, 'payment_id' => $payment,
        'party_type' => 'merchant', 'party_label' => 'Merchant', 'percent' => 100, 'gross_amount' => 1, 'commission_amount' => 1, 'payout_id' => $payout, 'invoice_id' => $invoice, 'settlement_id' => $settlement]);
    DB::table('pos_roundup_donations')->insert(['uuid' => Str::uuid(), 'company_id' => $b->id, 'branch_id' => $branch->id, 'device_id' => $device->id, 'order_id' => $order, 'payment_id' => $payment, 'amount' => 0.1]);
    $ingredient = DB::table('pos_ingredients')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $a->id, 'name' => 'Flour', 'unit' => 'kg']);
    DB::table('pos_stock_movements')->insert(['branch_id' => $branch->id, 'ingredient_id' => $ingredient, 'movement_type' => 'adjustment', 'quantity' => 1]);
    $product = DB::table('pos_products')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $a->id, 'name' => 'Bread', 'base_price' => 1]);
    DB::table('pos_product_stock_movements')->insert(['company_id' => $a->id, 'branch_id' => $branch->id, 'product_id' => $product, 'movement_type' => 'adjustment', 'quantity' => 1]);
    $checks = app(TenantIntegrityChecks::class)->run();
    foreach (['order_customer', 'order_table', 'order_branch', 'order_device', 'payment_commission', 'payment_roundup', 'stock_ingredient', 'stock_product', 'commission_order', 'roundup_order', 'payout_order', 'invoice_order', 'settlement_order'] as $name) {
        expect($checks[$name]['count'], $name)->toBe(1);
    }
});

it('W11 uses assignment history at receipt time and reports unknown or ambiguous history instead of guessing', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $ab = Branch::factory()->create(['company_id' => $a->id]);
    $bb = Branch::factory()->create(['company_id' => $b->id]);
    $device = Device::factory()->create(['company_id' => $b->id, 'branch_id' => $bb->id]);
    DB::table('pos_device_assignments_history')->insert([
        ['device_id' => $device->id, 'company_id' => $a->id, 'branch_id' => $ab->id, 'assigned_at' => now()->subDays(2), 'unassigned_at' => now()->subHour()],
        ['device_id' => $device->id, 'company_id' => $b->id, 'branch_id' => $bb->id, 'assigned_at' => now()->subHour(), 'unassigned_at' => null],
    ]);
    $id = DB::table('pos_sync_events')->insertGetId(['device_id' => $device->id, 'company_id' => $a->id, 'branch_id' => $ab->id, 'client_event_id' => Str::uuid(),
        'event_type' => 'expense.log', 'payload_json' => '{}', 'client_timestamp' => now()->subDay(), 'server_received_at' => now()->subDay(), 'ack_status' => 'processed']);
    expect(app(TenantIntegrityChecks::class)->run()['sync_assignment_mismatch']['count'])->toBe(0);
    DB::table('pos_sync_events')->where('id', $id)->update(['company_id' => $b->id]);
    expect(app(TenantIntegrityChecks::class)->run()['sync_assignment_mismatch']['sample_ids'])->toBe([$id]);
    DB::table('pos_sync_events')->where('id', $id)->update(['server_received_at' => now()->subDays(3)]);
    expect(app(TenantIntegrityChecks::class)->run()['sync_assignment_unverified']['sample_ids'])->toBe([$id]);
    DB::table('pos_sync_events')->where('id', $id)->update(['server_received_at' => now()->subHour()]);
    expect(app(TenantIntegrityChecks::class)->run()['sync_assignment_unverified']['sample_ids'])->toBe([$id]);
});

it('W11 schedules the integrity command nightly with overlap protection', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'pos-tenant-integrity');
    expect($event)->not->toBeNull()->and($event->expression)->toBe('30 2 * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});
