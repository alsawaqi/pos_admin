<?php

declare(strict_types=1);

/**
 * v2 #17 (Phase B) — merchant payout workflow.
 *
 *   GET/POST /admin/api/v1/payouts ; POST .../{uuid}/mark-paid ; .../{uuid}/cancel
 *
 * Create claims the period's unsettled merchant-commission rows (payout_id) so
 * earnings can't be paid twice; pending → paid | cancelled (cancel releases the
 * rows). Read on reports.view; money actions on settings.manage.
 */

use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Payout;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function actingAsPayoutAdmin(\Tests\TestCase $test): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

function seedPayoutSale(int $companyId, int $orderId, string $platform, string $bank, string $merchant, string $occurredAt = '2026-06-12 10:00:00', int $branchId = 10): void
{
    $gross = number_format((float) $platform + (float) $bank + (float) $merchant, 3, '.', '');
    $sort = 0;
    foreach (['platform' => $platform, 'bank' => $bank, 'merchant' => $merchant] as $party => $amount) {
        DB::table('pos_sale_commissions')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'device_id' => 1,
            'order_id' => $orderId,
            'party_type' => $party,
            'party_label' => ucfirst($party),
            'percent' => 0,
            'gross_amount' => $gross,
            'commission_amount' => $amount,
            'sort_order' => $sort++,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}

it('gates read on reports.view and writes on settings.manage', function (): void {
    $this->actingAs(User::factory()->create()); // no platform role

    $this->getJson('/admin/api/v1/payouts')->assertForbidden();
    $c = Company::factory()->create();
    $this->postJson('/admin/api/v1/payouts', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])
        ->assertForbidden();
});

it('creates a pending payout that claims the period merchant rows', function (): void {
    actingAsPayoutAdmin($this);
    $c = Company::factory()->create();
    seedPayoutSale($c->id, 1, '0.060', '0.090', '2.850'); // gross 3.000
    seedPayoutSale($c->id, 2, '0.040', '0.000', '1.960'); // gross 2.000

    $res = $this->postJson('/admin/api/v1/payouts', [
        'company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertCreated();

    $d = $res->json('data');
    expect($d['status'])->toBe('pending');
    expect($d['net_amount'])->toBe('4.810');   // 2.850 + 1.960
    expect($d['gross_amount'])->toBe('5.000');
    expect($d['platform_amount'])->toBe('0.100');
    expect($d['bank_amount'])->toBe('0.090');
    expect($d['sales_count'])->toBe(2);

    // The two MERCHANT rows are claimed; the platform/bank rows are not.
    $payout = Payout::firstOrFail();
    expect(DB::table('pos_sale_commissions')->where('party_type', 'merchant')->whereNotNull('payout_id')->count())->toBe(2);
    expect((int) DB::table('pos_sale_commissions')->where('party_type', 'merchant')->value('payout_id'))->toBe((int) $payout->id);
    expect(DB::table('pos_sale_commissions')->where('party_type', 'platform')->whereNotNull('payout_id')->count())->toBe(0);
});

it('refuses a payout when nothing is unsettled, incl. a double create', function (): void {
    actingAsPayoutAdmin($this);
    $c = Company::factory()->create();

    // Empty period.
    $this->postJson('/admin/api/v1/payouts', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])
        ->assertStatus(422);

    // After a successful payout, the same period has nothing left to claim.
    seedPayoutSale($c->id, 1, '0.060', '0.090', '2.850');
    $this->postJson('/admin/api/v1/payouts', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->assertCreated();
    $this->postJson('/admin/api/v1/payouts', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])
        ->assertStatus(422);
});

it('marks a pending payout paid with a reference', function (): void {
    actingAsPayoutAdmin($this);
    $c = Company::factory()->create();
    seedPayoutSale($c->id, 1, '0.060', '0.090', '2.850');
    $uuid = $this->postJson('/admin/api/v1/payouts', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->json('data.uuid');

    $res = $this->postJson("/admin/api/v1/payouts/{$uuid}/mark-paid", ['reference' => 'BANK-TXN-77'])->assertOk();
    expect($res->json('data.status'))->toBe('paid');
    expect($res->json('data.reference'))->toBe('BANK-TXN-77');
    expect($res->json('data.paid_at'))->not->toBeNull();

    // Terminal — a second mark-paid is rejected.
    $this->postJson("/admin/api/v1/payouts/{$uuid}/mark-paid")->assertStatus(422);
});

it('cancels a pending payout and releases its rows for re-payout', function (): void {
    actingAsPayoutAdmin($this);
    $c = Company::factory()->create();
    seedPayoutSale($c->id, 1, '0.060', '0.090', '2.850');
    $uuid = $this->postJson('/admin/api/v1/payouts', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->json('data.uuid');

    $this->postJson("/admin/api/v1/payouts/{$uuid}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    // Rows released → a fresh payout for the same period succeeds again.
    expect(DB::table('pos_sale_commissions')->where('party_type', 'merchant')->whereNotNull('payout_id')->count())->toBe(0);
    $this->postJson('/admin/api/v1/payouts', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->assertCreated();
});

it('payout net_amount matches the settlement report for the same window', function (): void {
    actingAsPayoutAdmin($this);
    $c = Company::factory()->create();
    seedPayoutSale($c->id, 1, '0.060', '0.090', '2.850');
    seedPayoutSale($c->id, 2, '0.040', '0.000', '1.960');

    $payoutNet = $this->postJson('/admin/api/v1/payouts', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])
        ->assertCreated()->json('data.net_amount');
    $reportNet = $this->getJson("/admin/api/v1/settlement-report?from=2026-06-01&to=2026-06-30&company_uuid={$c->uuid}")
        ->assertOk()->json('data.by_merchant.0.merchant_net');

    // The payable the platform records == the merchant net the report shows.
    expect($payoutNet)->toBe('4.810');
    expect($reportNet)->toBe($payoutNet);
});

it('pays out and reports the SETTLED net once a card sale is reconciled', function (): void {
    actingAsPayoutAdmin($this);
    $c = Company::factory()->create();
    seedPayoutSale($c->id, 1, '0.060', '0.090', '2.850'); // estimate: gross 3.000

    // Simulate a settlement: actual bank 0.150 (> est 0.090) → the merchant
    // absorbs the 0.060 variance (2.790); platform unchanged. Σ stays 3.000.
    DB::table('pos_sale_commissions')->where('order_id', 1)->where('party_type', 'bank')
        ->update(['settled_amount' => '0.150', 'is_settled' => true]);
    DB::table('pos_sale_commissions')->where('order_id', 1)->where('party_type', 'merchant')
        ->update(['settled_amount' => '2.790', 'is_settled' => true]);
    DB::table('pos_sale_commissions')->where('order_id', 1)->where('party_type', 'platform')
        ->update(['settled_amount' => '0.060', 'is_settled' => true]);

    $payout = $this->postJson('/admin/api/v1/payouts', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])
        ->assertCreated()->json('data');
    expect($payout['net_amount'])->toBe('2.790')       // settled, not the 2.850 estimate
        ->and($payout['bank_amount'])->toBe('0.150')    // actual bank fee, not 0.090
        ->and($payout['gross_amount'])->toBe('3.000');  // Σ preserved

    $report = $this->getJson("/admin/api/v1/settlement-report?from=2026-06-01&to=2026-06-30&company_uuid={$c->uuid}")
        ->assertOk()->json('data.by_merchant.0');
    expect($report['merchant_net'])->toBe('2.790')
        ->and($report['bank'])->toBe('0.150');
});

it('returns a payout per-branch breakdown for the statement', function (): void {
    actingAsPayoutAdmin($this);
    $c = Company::factory()->create();
    $main = Branch::factory()->create(['company_id' => $c->id, 'name' => 'Main']);
    $mall = Branch::factory()->create(['company_id' => $c->id, 'name' => 'Mall']);
    seedPayoutSale($c->id, 1, '0.060', '0.090', '2.850', '2026-06-12 10:00:00', $main->id);
    seedPayoutSale($c->id, 2, '0.040', '0.000', '1.960', '2026-06-12 10:00:00', $mall->id);

    $uuid = $this->postJson('/admin/api/v1/payouts', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])
        ->assertCreated()->json('data.uuid');

    $lines = $this->getJson("/admin/api/v1/payouts/{$uuid}/lines")->assertOk()->json('data');

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['branch_name'])->toBe('Main')   // sorted by net desc
        ->and($lines[0]['merchant_net'])->toBe('2.850')
        ->and($lines[0]['bank'])->toBe('0.090')
        ->and($lines[0]['num_sales'])->toBe(1)
        ->and($lines[1]['branch_name'])->toBe('Mall')
        ->and($lines[1]['merchant_net'])->toBe('1.960');
});

it('lists payouts filtered by company + status', function (): void {
    actingAsPayoutAdmin($this);
    $c = Company::factory()->create(['name' => 'Alpha Co']);
    seedPayoutSale($c->id, 1, '0.060', '0.090', '2.850');
    $this->postJson('/admin/api/v1/payouts', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->assertCreated();

    $rows = $this->getJson("/admin/api/v1/payouts?company_uuid={$c->uuid}&status=pending")->assertOk()->json('data');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['company_name'])->toBe('Alpha Co');
    expect($rows[0]['net_amount'])->toBe('2.850');
});
