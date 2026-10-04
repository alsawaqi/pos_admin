<?php

declare(strict_types=1);

/*
 * LAUNCH-P5 — the admin payment-reversal copy records who voided an order and
 * who approved it, like pos_api's order.void: an approved card VOID stamps
 * pos_orders.voided_by_staff_id with the reversal's requester and
 * void_approved_by_staff_id with its PIN-verified approver.
 */

use App\Actions\Payments\ApplyReversalOrderEffectsAction;
use App\Actions\Payments\ReturnRefundedUnitStockAction;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\PaymentReversal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('stamps the requester as the voider and the PIN-verified approver on a card reversal void', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    DB::table('banks')->insert(['id' => 1, 'name' => 'Synthetic bank', 'short_name' => 'Synthetic bank', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now()]);
    $device = Device::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'bank_id' => 1]);
    $staff = fn (string $position): int => (int) DB::table('pos_staff')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'name' => ucfirst($position), 'pin_hash' => 'unused', 'position' => $position,
    ]);
    $cashier = $staff('cashier');
    $manager = $staff('manager');
    $orderId = (int) DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id, 'staff_id' => $cashier,
        'order_type' => 'quick', 'source' => 'main_pos', 'status' => 'paid', 'subtotal' => '2.000', 'tax_total' => '0.000',
        'grand_total' => '2.000', 'opened_at' => now()->subHour(), 'closed_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $paymentId = (int) DB::table('pos_payments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'card', 'amount' => '2.000',
        'status' => 'success', 'pending_reconciliation' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $reason = (int) DB::table('pos_void_reasons')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Customer changed mind',
        'code' => 'CHANGED', 'is_active' => true, 'affects_inventory' => false,
    ]);
    $reversalId = (int) DB::table('pos_payment_reversals')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_id' => $orderId, 'payment_id' => $paymentId, 'kind' => 'void', 'amount' => '2.000',
        'amount_baisas' => 2000, 'currency_code' => '0512', 'status' => 'approved', 'softpos_provider' => 'mosambee_dhofar',
        'softpos_package' => 'com.mosambee.dhofar.softpos', 'bank_id' => 1, 'device_id' => $device->id,
        'requested_by_staff_id' => $cashier, 'approved_by_staff_id' => $manager, 'void_reason_id' => $reason,
        'client_request_id' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64), 'attempted_at' => now(),
    ]);

    DB::transaction(fn () => (new ApplyReversalOrderEffectsAction(new ReturnRefundedUnitStockAction))->void(
        DB::table('pos_orders')->where('id', $orderId)->first(),
        PaymentReversal::query()->findOrFail($reversalId),
    ));

    $order = DB::table('pos_orders')->where('id', $orderId)->first();
    expect($order->status)->toBe('void')
        ->and((int) $order->voided_by_staff_id)->toBe($cashier)
        ->and((int) $order->void_approved_by_staff_id)->toBe($manager);
});
