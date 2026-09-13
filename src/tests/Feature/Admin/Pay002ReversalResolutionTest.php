<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pay002AdminReversal(string $kind = 'refund', string $status = 'uncertain', string $amount = '2.500', bool $keepStock = false): array
{
    seedBank(1, 'Synthetic bank');
    $paymentId = seedCardPayment(['amount' => '5.000', 'bank_response' => json_encode(['reversal_pending_review' => true])]);
    $payment = DB::table('pos_payments')->where('id', $paymentId)->first();
    $order = DB::table('pos_orders')->where('id', $payment->order_id)->first();
    $device = Device::factory()->create(['company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'bank_id' => 1]);
    $staff = DB::table('pos_staff')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $order->company_id, 'branch_id' => $order->branch_id,
        'name' => 'Manager', 'pin_hash' => 'unused', 'position' => 'manager',
    ]);
    $reason = DB::table('pos_void_reasons')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $order->company_id, 'name' => 'Void reason',
        'code' => 'NOT_MADE', 'is_active' => true, 'affects_inventory' => $keepStock,
    ]);
    $product = DB::table('pos_products')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $order->company_id, 'name' => 'Retail item',
        'base_price' => '2.500', 'stock_mode' => 'unit', 'status' => 'active',
    ]);
    DB::table('pos_branch_product')->insert(['branch_id' => $order->branch_id, 'product_id' => $product, 'stock_qty' => 5]);
    $item = DB::table('pos_order_items')->insertGetId([
        'order_id' => $order->id, 'product_id' => $product, 'product_name_snapshot' => 'Retail item',
        'qty' => 2, 'unit_price_snapshot' => '2.500', 'line_total' => '5.000', 'line_discount' => 0,
        'status' => 'open', 'component_snapshot_json' => '[]',
    ]);
    $uuid = (string) Str::uuid();
    $id = DB::table('pos_payment_reversals')->insertGetId([
        'uuid' => $uuid, 'company_id' => $order->company_id, 'branch_id' => $order->branch_id,
        'order_id' => $order->id, 'payment_id' => $paymentId, 'kind' => $kind, 'amount' => $amount,
        'amount_baisas' => (int) round((float) $amount * 1000), 'currency_code' => '0512',
        'status' => $status, 'softpos_provider' => 'mosambee_dhofar', 'softpos_package' => 'com.mosambee.dhofar.softpos',
        'bank_id' => 1, 'approved_by_staff_id' => $staff, 'device_id' => $device->id,
        'client_request_id' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64),
        'void_reason_id' => $kind === 'void' ? $reason : null, 'attempted_at' => now()->subHour(),
    ]);
    if ($kind === 'refund') {
        DB::table('pos_payment_reversal_lines')->insert([
            'reversal_id' => $id, 'order_item_id' => $item, 'qty' => $amount === '5.000' ? 2 : 1,
            'amount' => $amount, 'amount_baisas' => (int) round((float) $amount * 1000),
            'stock_mode_at_refund' => 'unit', 'returned_to_stock' => false,
        ]);
    }

    return compact('uuid', 'id', 'paymentId', 'order', 'product');
}

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    actingAsReconAdmin($this, PlatformRole::DeviceOperations->value);
});

it('requires device control and evidence before resolving an uncertain reversal', function (): void {
    $row = pay002AdminReversal();
    $url = '/admin/api/v1/payment-reversals/'.$row['uuid'].'/resolve';
    $this->postJson($url, ['outcome' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('evidence_note');
    $viewer = User::factory()->create();
    $viewer->assignRole(PlatformRole::Support->value);
    $this->actingAs($viewer)->postJson($url, ['outcome' => 'approved', 'evidence_note' => 'Bank matched'])->assertForbidden();
    expect(DB::table('pos_payments')->count())->toBe(1);
    expect(DB::table('pos_payment_reversals')->where('id', $row['id'])->value('status'))->toBe('uncertain');
    expect(AuditLog::where('event', 'payment_reversal.resolved')->count())->toBe(0);
});

it('applies an approved refund once with a negative ledger and unit stock return and audits evidence', function (string $amount, string $orderStatus, int $stock): void {
    $row = pay002AdminReversal(amount: $amount);
    $url = '/admin/api/v1/payment-reversals/'.$row['uuid'].'/resolve';
    $payload = ['outcome' => 'approved', 'evidence_note' => 'Matched reversal in bank statement', 'reversal_auth_code' => 'REV-AUTH', 'reversal_transaction_id' => 'REV-TX'];
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'approved');
    expect(DB::table('pos_orders')->where('id', $row['order']->id)->value('status'))->toBe($orderStatus);
    expect((float) DB::table('pos_payments')->where('id', $row['paymentId'])->value('refunded_total'))->toBe((float) $amount);
    expect((int) DB::table('pos_branch_product')->where('product_id', $row['product'])->value('stock_qty'))->toBe($stock);
    $this->assertDatabaseHas('pos_payments', ['direction' => 'reversal', 'softpos_auth_code' => 'REV-AUTH', 'amount' => '-'.$amount]);
    $this->assertDatabaseHas('pos_product_stock_movements', ['movement_type' => 'refund_return', 'reference_type' => 'pos_payment_reversals']);
    expect(AuditLog::where('event', 'payment_reversal.resolved')->count())->toBe(1);
    $this->postJson($url, $payload)->assertStatus(409)->assertJsonPath('code', 'reversal_already_closed');
    expect(DB::table('pos_payments')->count())->toBe(2);
    expect(DB::table('pos_product_stock_movements')->count())->toBe(1);
    expect(AuditLog::where('event', 'payment_reversal.resolved')->count())->toBe(1);
})->with([['2.500', 'paid', 6], ['5.000', 'refunded', 7]]);

it('declines uncertain reversal without ledger stock or order effects', function (): void {
    $row = pay002AdminReversal();
    $this->postJson('/admin/api/v1/payment-reversals/'.$row['uuid'].'/resolve', [
        'outcome' => 'declined', 'evidence_note' => 'Bank confirms no reversal',
    ])->assertOk()->assertJsonPath('data.status', 'declined');
    expect(DB::table('pos_payments')->count())->toBe(1);
    expect(DB::table('pos_orders')->where('id', $row['order']->id)->value('status'))->toBe('paid');
    expect(DB::table('pos_product_stock_movements')->count())->toBe(0);
    expect(json_decode(DB::table('pos_payments')->where('id', $row['paymentId'])->value('bank_response'), true))->not->toHaveKey('reversal_pending_review');
    expect(AuditLog::where('event', 'payment_reversal.resolved')->count())->toBe(1);
});

it('resolves an approved void through the mirrored core respecting the inventory reason', function (bool $keepStock, int $stock): void {
    $row = pay002AdminReversal('void', amount: '5.000', keepStock: $keepStock);
    $this->postJson('/admin/api/v1/payment-reversals/'.$row['uuid'].'/resolve', [
        'outcome' => 'approved', 'evidence_note' => 'Void confirmed', 'reversal_auth_code' => 'VOID-AUTH',
    ])->assertOk()->assertJsonPath('data.status', 'approved');
    expect(DB::table('pos_orders')->where('id', $row['order']->id)->value('status'))->toBe('void');
    expect(DB::table('pos_order_items')->where('order_id', $row['order']->id)->value('status'))->toBe('void');
    expect(DB::table('pos_payments')->where('id', $row['paymentId'])->value('voided_at'))->not->toBeNull();
    expect((int) DB::table('pos_branch_product')->where('product_id', $row['product'])->value('stock_qty'))->toBe($stock);
    $this->assertDatabaseHas('pos_payments', ['direction' => 'reversal', 'amount' => '-5.000']);
})->with([[false, 7], [true, 5]]);

it('refuses admin resolution of pending reversals before any write', function (): void {
    $row = pay002AdminReversal(status: 'pending');
    $before = DB::table('pos_payment_reversals')->get()->toJson();
    $this->postJson('/admin/api/v1/payment-reversals/'.$row['uuid'].'/resolve', [
        'outcome' => 'approved', 'evidence_note' => 'Premature',
    ])->assertStatus(409)->assertJsonPath('code', 'reversal_already_closed');
    expect(DB::table('pos_payment_reversals')->get()->toJson())->toBe($before);
    expect(DB::table('pos_payments')->count())->toBe(1);
    expect(AuditLog::where('event', 'payment_reversal.resolved')->count())->toBe(0);
});
