<?php

declare(strict_types=1);

/**
 * Admin platform Settlement Report endpoint (v2 #17 Phase A).
 *
 *   GET /admin/api/v1/settlement-report?from=&to=&company_uuid=
 *
 * Aggregates pos_sale_commissions across merchants: per-merchant gross +
 * platform/bank/other takes + merchant net, plus platform totals. reports.view
 * gated; window-bounded (occurred_at); optional company_uuid scope.
 */

use App\Enums\PlatformRole;
use App\Models\Company;
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

function actingAsSettlementAdmin(\Tests\TestCase $test): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

function seedSettlementSale(int $companyId, int $orderId, int $branchId, string $platform, string $bank, string $merchant, string $occurredAt = '2026-06-12 10:00:00'): void
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

it('is gated under reports.view', function (): void {
    $this->actingAs(User::factory()->create());

    $this->getJson('/admin/api/v1/settlement-report')->assertForbidden();
});

it('aggregates per-merchant payables + platform totals', function (): void {
    actingAsSettlementAdmin($this);

    $alpha = Company::factory()->create(['name' => 'Alpha Co']);
    $beta = Company::factory()->create(['name' => 'Beta Co']);
    // Alpha: 2 sales. Beta: 1 sale.
    seedSettlementSale($alpha->id, 1, 10, '0.060', '0.090', '2.850'); // gross 3.000
    seedSettlementSale($alpha->id, 2, 10, '0.040', '0.000', '1.960'); // gross 2.000
    seedSettlementSale($beta->id, 3, 20, '0.100', '0.000', '4.900'); // gross 5.000
    // Outside window — excluded.
    seedSettlementSale($beta->id, 4, 20, '9.000', '0.000', '1.000', '2026-05-01 10:00:00');

    $data = $this->getJson('/admin/api/v1/settlement-report?from=2026-06-01&to=2026-06-30')
        ->assertOk()->json('data');

    $h = $data['headline'];
    expect($h['gross'])->toBe('10.000');            // 3 + 2 + 5
    expect($h['platform_revenue'])->toBe('0.200');  // 0.060 + 0.040 + 0.100
    expect($h['bank_total'])->toBe('0.090');
    expect($h['merchant_payable'])->toBe('9.710');  // 2.850 + 1.960 + 4.900
    expect($h['num_sales'])->toBe(3);
    expect($h['num_merchants'])->toBe(2);

    // by_merchant sorted by merchant_net desc → Alpha (4.810) before Beta (4.900)?
    // Alpha net = 2.850 + 1.960 = 4.810; Beta = 4.900 → Beta first.
    expect($data['by_merchant'])->toHaveCount(2);
    expect($data['by_merchant'][0]['company_name'])->toBe('Beta Co');
    expect($data['by_merchant'][0]['merchant_net'])->toBe('4.900');
    expect($data['by_merchant'][1]['company_name'])->toBe('Alpha Co');
    expect($data['by_merchant'][1]['merchant_net'])->toBe('4.810');
    expect($data['by_merchant'][1]['num_sales'])->toBe(2);
});

it('scopes to a single merchant via company_uuid', function (): void {
    actingAsSettlementAdmin($this);

    $alpha = Company::factory()->create(['name' => 'Alpha Co']);
    $beta = Company::factory()->create(['name' => 'Beta Co']);
    seedSettlementSale($alpha->id, 1, 10, '0.060', '0.090', '2.850');
    seedSettlementSale($beta->id, 2, 20, '5.000', '0.000', '5.000');

    $data = $this->getJson("/admin/api/v1/settlement-report?from=2026-06-01&to=2026-06-30&company_uuid={$alpha->uuid}")
        ->assertOk()->json('data');

    expect($data['headline']['num_merchants'])->toBe(1);
    expect($data['headline']['merchant_payable'])->toBe('2.850');
    expect($data['by_merchant'])->toHaveCount(1);
    expect($data['by_merchant'][0]['company_name'])->toBe('Alpha Co');
});
