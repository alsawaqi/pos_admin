<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 0 exit — W-D2 (EXIT-11, matrix flag F-4): the SEQUENTIAL bank-file
 * void-ORDER guard. A bank statement can list the tender of an order that
 * was ALREADY voided before the admin commits the file (refund/exception
 * territory). Committing that payment id must settle nothing: no tender
 * flip, no commission minted, no donation state change, no forward, no
 * audit. The existing regressions only pinned donation-status handling on
 * this path — never the void ORDER itself.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    config(['services.charity.url' => 'http://charity.test']);
});

function bankVoidGuardActingAs(TestCase $test): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

/**
 * An ALREADY-void order whose card tender still sits pending_reconciliation
 * (the force-recorded charge is unresolved evidence), plus an active
 * commission profile — so a missing guard would observably mint rows — and
 * an unforwarded round-up riding the tender.
 *
 * @return array{order_id: int, payment_id: int, donation_id: int}
 */
function bankVoidGuardSeedVoidOrder(): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $device = Device::factory()->assigned()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
    ]);

    $orderId = (int) DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'status' => 'void', 'source' => 'main_pos',
        'subtotal' => '5.000', 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => '5.000',
        'opened_at' => now(), 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $paymentId = (int) DB::table('pos_payments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'card',
        'amount' => '5.000', 'status' => 'pending_reconciliation', 'pending_reconciliation' => true,
        'softpos_reference' => 'NFC-VOID-1', 'softpos_auth_code' => 'A99',
        'bank_response' => json_encode(['status' => 'timeout']),
        'device_id' => $device->id, 'terminal_id' => $device->terminal_id,
        'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $profileId = (int) DB::table('pos_commission_profiles')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'is_active' => true, 'merchant_percent' => 95,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([['platform', 'Platform', 2, 0], ['bank', 'Acme Bank', 3, 1]] as [$type, $label, $percent, $sort]) {
        DB::table('pos_commission_shares')->insert([
            'commission_profile_id' => $profileId, 'party_type' => $type, 'label' => $label,
            'percent' => $percent, 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $donationId = (int) DB::table('pos_roundup_donations')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'company_id' => $company->id, 'branch_id' => $branch->id,
        'device_id' => $device->id, 'order_id' => $orderId, 'payment_id' => $paymentId,
        'terminal_id' => $device->terminal_id,
        'amount' => '0.200', 'bank_response' => json_encode(['status' => 'timeout']),
        'bank_id' => $device->bank_id,
        'commission_profile_id' => $device->commission_profile_id,
        'organization_id' => $device->organization_id,
        'branch_name' => $branch->name,
        'country_id' => $branch->country_id,
        'region_id' => $branch->region_id,
        'district_id' => $branch->district_id,
        'city_id' => $branch->city_id,
        'latitude' => $branch->latitude,
        'longitude' => $branch->longitude,
        'status' => 'pending', 'source' => 'pos_roundup',
        'occurred_at' => now(), 'forwarded_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['order_id' => $orderId, 'payment_id' => $paymentId, 'donation_id' => $donationId];
}

it('a bank file listing an already-void order\'s tender settles nothing', function (): void {
    Http::fake(['*' => Http::response(['success' => true], 201)]);
    bankVoidGuardActingAs($this);
    $ctx = bankVoidGuardSeedVoidOrder();

    $this->postJson('/admin/api/v1/bank-reconciliation/commit', ['payment_ids' => [$ctx['payment_id']]])
        ->assertOk()
        ->assertJsonPath('data.reconciled', 0)
        ->assertJsonPath('data.payment_ids', [])
        ->assertJsonPath('data.effects.commissions_recorded', 0)
        ->assertJsonPath('data.effects.donations_forwarded', 0)
        ->assertJsonPath('data.effects.orders_settled', []);

    // No commission minted for money that belongs in refund review.
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(0);

    // The tender is untouched: still pending, still queue evidence.
    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'],
        'status' => 'pending_reconciliation',
        'pending_reconciliation' => true,
        'reconciled_at' => null,
    ]);

    // The donation is neither overwritten nor forwarded.
    $donation = DB::table('pos_roundup_donations')->find($ctx['donation_id']);
    expect($donation->status)->toBe('pending');
    expect($donation->forwarded_at)->toBeNull();

    // No settlement or approval audit trail was fabricated.
    expect(DB::table('pos_audit_logs')->count())->toBe(0);
    Http::assertNothingSent();
});

it('a mixed bank file settles the live order but never the void one', function (): void {
    Http::fake(['*' => Http::response(['success' => true], 201)]);
    bankVoidGuardActingAs($this);
    $void = bankVoidGuardSeedVoidOrder();
    $live = bankVoidGuardSeedVoidOrder();
    DB::table('pos_orders')->where('id', $live['order_id'])->update(['status' => 'paid', 'updated_at' => now()]);

    $this->postJson('/admin/api/v1/bank-reconciliation/commit', [
        'payment_ids' => [$void['payment_id'], $live['payment_id']],
    ])
        ->assertOk()
        ->assertJsonPath('data.reconciled', 1)
        ->assertJsonPath('data.payment_ids', [$live['payment_id']])
        ->assertJsonPath('data.effects.orders_settled', [$live['order_id']]);

    // The live half of the batch settles fully...
    $this->assertDatabaseHas('pos_payments', [
        'id' => $live['payment_id'], 'status' => 'success', 'pending_reconciliation' => false,
    ]);
    expect(DB::table('pos_sale_commissions')->where('order_id', $live['order_id'])->count())->toBe(3);

    // ...while the void order's tender, commission, and donation stay put.
    $this->assertDatabaseHas('pos_payments', [
        'id' => $void['payment_id'], 'status' => 'pending_reconciliation', 'pending_reconciliation' => true,
    ]);
    expect(DB::table('pos_sale_commissions')->where('order_id', $void['order_id'])->count())->toBe(0);
    expect(DB::table('pos_roundup_donations')->find($void['donation_id'])->status)->toBe('pending');
    expect(DB::table('pos_roundup_donations')->find($void['donation_id'])->forwarded_at)->toBeNull();
    Http::assertSentCount(1);
});
