<?php

declare(strict_types=1);

/**
 * The Sales-tab drill — GET /admin/api/v1/orders/summary (reports.view).
 *
 * Merchants → branches with sales counts + gross + per-tender-method totals for
 * a window. PAID orders only; failed tenders excluded; gross = grand_total (tax
 * included, round-up never part of it).
 */

use App\Enums\PlatformRole;
use App\Models\Branch;
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

function actingAsSalesSummaryAdmin(\Tests\TestCase $test): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

/**
 * @param  list<array{method: string, omr: string, status?: string}>  $tenders
 */
function seedSummarySale(Company $company, Branch $branch, string $grandTotal, array $tenders, string $status = 'paid', string $openedAt = '2026-06-12 10:00:00'): int
{
    $orderId = DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'status' => $status, 'source' => 'main_pos',
        'subtotal' => $grandTotal, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => $grandTotal,
        'opened_at' => $openedAt, 'closed_at' => $openedAt, 'created_at' => $openedAt, 'updated_at' => $openedAt,
    ]);
    foreach ($tenders as $t) {
        DB::table('pos_payments')->insert([
            'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => $t['method'],
            'amount' => $t['omr'], 'status' => $t['status'] ?? 'success', 'pending_reconciliation' => false,
            'captured_at' => $openedAt, 'created_at' => $openedAt, 'updated_at' => $openedAt,
        ]);
    }

    return $orderId;
}

it('drills merchants → branches with sales counts + per-method totals', function (): void {
    actingAsSalesSummaryAdmin($this);
    $c = Company::factory()->create(['name' => 'Kaldi']);
    $main = Branch::factory()->create(['company_id' => $c->id, 'name' => 'Main']);
    $mall = Branch::factory()->create(['company_id' => $c->id, 'name' => 'Mall']);

    seedSummarySale($c, $main, '10.000', [['method' => 'card', 'omr' => '10.000']]);
    seedSummarySale($c, $main, '5.000', [['method' => 'cash', 'omr' => '5.000']]);
    seedSummarySale($c, $mall, '2.000', [['method' => 'bank_pos', 'omr' => '2.000']]);
    // Mixed tender: 3 card + 1 cash.
    seedSummarySale($c, $mall, '4.000', [['method' => 'card', 'omr' => '3.000'], ['method' => 'cash', 'omr' => '1.000']]);

    $rows = $this->getJson('/admin/api/v1/orders/summary?from=2026-06-01&to=2026-06-30')->assertOk()->json('data');

    expect($rows)->toHaveCount(1);
    $m = $rows[0];
    expect($m['company_name'])->toBe('Kaldi')
        ->and($m['sales_count'])->toBe(4)
        ->and($m['gross_total'])->toBe('21.000')
        ->and($m['card_total'])->toBe('13.000')
        ->and($m['cash_total'])->toBe('6.000')
        ->and($m['bank_pos_total'])->toBe('2.000');

    expect($m['branches'])->toHaveCount(2);
    $mainRow = collect($m['branches'])->firstWhere('branch_name', 'Main');
    $mallRow = collect($m['branches'])->firstWhere('branch_name', 'Mall');
    expect($mainRow['sales_count'])->toBe(2)
        ->and($mainRow['gross_total'])->toBe('15.000')
        ->and($mainRow['card_total'])->toBe('10.000')
        ->and($mainRow['cash_total'])->toBe('5.000')
        ->and($mallRow['gross_total'])->toBe('6.000')
        ->and($mallRow['bank_pos_total'])->toBe('2.000')
        ->and($mallRow['card_total'])->toBe('3.000');
});

it('counts only PAID orders and skips failed tenders', function (): void {
    actingAsSalesSummaryAdmin($this);
    $c = Company::factory()->create();
    $b = Branch::factory()->create(['company_id' => $c->id]);

    seedSummarySale($c, $b, '10.000', [['method' => 'card', 'omr' => '10.000']]);
    seedSummarySale($c, $b, '9.000', [['method' => 'cash', 'omr' => '9.000']], status: 'open');   // not a sale yet
    seedSummarySale($c, $b, '8.000', [['method' => 'cash', 'omr' => '8.000']], status: 'void');   // reversed
    // A failed card attempt then cash — only the cash counts toward methods.
    seedSummarySale($c, $b, '4.000', [
        ['method' => 'card', 'omr' => '4.000', 'status' => 'failed'],
        ['method' => 'cash', 'omr' => '4.000'],
    ]);

    $rows = $this->getJson('/admin/api/v1/orders/summary?from=2026-06-01&to=2026-06-30')->assertOk()->json('data');

    expect($rows[0]['sales_count'])->toBe(2)
        ->and($rows[0]['gross_total'])->toBe('14.000')
        ->and($rows[0]['card_total'])->toBe('10.000')
        ->and($rows[0]['cash_total'])->toBe('4.000');
});

it('reports the verification progress per branch (Step 3 completion)', function (): void {
    actingAsSalesSummaryAdmin($this);
    $c = Company::factory()->create();
    $b = Branch::factory()->create(['company_id' => $c->id]);

    $verified = seedSummarySale($c, $b, '10.000', [['method' => 'cash', 'omr' => '10.000']]);
    $pending = seedSummarySale($c, $b, '5.000', [['method' => 'card', 'omr' => '5.000']]);
    // Commission rows: both sales commissioned; only the first verified.
    foreach ([[$verified, true], [$pending, false]] as [$orderId, $isSettled]) {
        foreach ([['platform', '0.200'], ['merchant', '9.800']] as $i => [$party, $amt]) {
            DB::table('pos_sale_commissions')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $c->id, 'branch_id' => $b->id, 'device_id' => 1,
                'order_id' => $orderId, 'party_type' => $party, 'party_label' => ucfirst($party), 'percent' => 0,
                'gross_amount' => '10.000', 'commission_amount' => $amt, 'sort_order' => $i,
                'is_settled' => $isSettled, 'settled_amount' => $isSettled ? $amt : null,
                'occurred_at' => '2026-06-12 10:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    $rows = $this->getJson('/admin/api/v1/orders/summary?from=2026-06-01&to=2026-06-30')->assertOk()->json('data');

    expect($rows[0]['commissioned_count'])->toBe(2)
        ->and($rows[0]['verified_count'])->toBe(1)
        ->and($rows[0]['branches'][0]['commissioned_count'])->toBe(2)
        ->and($rows[0]['branches'][0]['verified_count'])->toBe(1);
});

it('scopes the drill to pure cash/bank-POS sales with scope=cash_bank', function (): void {
    actingAsSalesSummaryAdmin($this);
    $c = Company::factory()->create();
    $b = Branch::factory()->create(['company_id' => $c->id]);

    seedSummarySale($c, $b, '10.000', [['method' => 'card', 'omr' => '10.000']]);   // excluded
    seedSummarySale($c, $b, '5.000', [['method' => 'cash', 'omr' => '5.000']]);     // included
    seedSummarySale($c, $b, '2.000', [['method' => 'bank_pos', 'omr' => '2.000']]); // included
    // Mixed card+cash rides the CARD flow — excluded from the cash page.
    seedSummarySale($c, $b, '4.000', [['method' => 'card', 'omr' => '3.000'], ['method' => 'cash', 'omr' => '1.000']]);

    $rows = $this->getJson('/admin/api/v1/orders/summary?from=2026-06-01&to=2026-06-30&scope=cash_bank')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['sales_count'])->toBe(2)          // pure cash + pure bank_pos only
        ->and($rows[0]['gross_total'])->toBe('7.000')
        ->and($rows[0]['cash_total'])->toBe('5.000')
        ->and($rows[0]['bank_pos_total'])->toBe('2.000')
        ->and($rows[0]['card_total'])->toBe('0.000');
});

it('bounds the drill to the window', function (): void {
    actingAsSalesSummaryAdmin($this);
    $c = Company::factory()->create();
    $b = Branch::factory()->create(['company_id' => $c->id]);
    seedSummarySale($c, $b, '10.000', [['method' => 'cash', 'omr' => '10.000']], openedAt: '2026-05-30 10:00:00'); // outside

    $rows = $this->getJson('/admin/api/v1/orders/summary?from=2026-06-01&to=2026-06-30')->assertOk()->json('data');
    expect($rows)->toBeArray()->toHaveCount(0);
});

it('gates the summary on reports.view', function (): void {
    $this->actingAs(User::factory()->create()); // no platform role

    $this->getJson('/admin/api/v1/orders/summary?from=2026-06-01&to=2026-06-30')->assertForbidden();
});
