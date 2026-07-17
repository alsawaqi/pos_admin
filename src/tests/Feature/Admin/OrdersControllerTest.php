<?php

declare(strict_types=1);

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

function actingAsSalesAdmin(\Tests\TestCase $test, string $role = PlatformRole::SuperAdmin->value): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

/** @param array<string, mixed> $attrs */
function makeSalesOrderRow(int $companyId, int $branchId, array $attrs): void
{
    DB::table('pos_orders')->insert(array_merge([
        'uuid' => (string) Str::uuid(),
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'order_type' => 'quick',
        'source' => 'main_pos',
        'status' => 'paid',
        'grand_total' => '5.000',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

it('lists orders across ALL merchants with totals + date filter', function (): void {
    actingAsSalesAdmin($this);

    $c1 = Company::factory()->create();
    $b1 = Branch::factory()->create(['company_id' => $c1->id]);
    $c2 = Company::factory()->create();
    $b2 = Branch::factory()->create(['company_id' => $c2->id]);

    makeSalesOrderRow($c1->id, $b1->id, ['grand_total' => '5.000', 'opened_at' => '2026-06-15 10:00:00']);
    makeSalesOrderRow($c2->id, $b2->id, ['grand_total' => '7.000', 'opened_at' => '2026-06-15 12:00:00']);
    makeSalesOrderRow($c1->id, $b1->id, ['grand_total' => '9.000', 'opened_at' => '2026-05-01 12:00:00']); // outside window

    $res = $this->getJson('/admin/api/v1/orders?from=2026-06-01&to=2026-06-30')->assertOk();

    expect($res->json('data'))->toHaveCount(2); // both companies, window-filtered
    expect($res->json('totals.count'))->toBe(2);
    expect($res->json('totals.grand_total'))->toBe('12.000'); // 5 + 7
});

it('carries the per-leg tender summary + round-up so a split reads off the list', function (): void {
    actingAsSalesAdmin($this);
    $c = Company::factory()->create();
    $b = Branch::factory()->create(['company_id' => $c->id]);
    $orderId = DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $c->id, 'branch_id' => $b->id,
        'order_type' => 'quick', 'source' => 'main_pos', 'status' => 'paid',
        'subtotal' => '10.000', 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => '10.000',
        'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Half cash / half card, the card leg carrying a 0.500 round-up; a failed
    // attempt must not appear.
    foreach ([['cash', '5.000', 'success', null], ['card', '5.000', 'success', '0.500'], ['card', '5.000', 'failed', null]] as [$method, $amount, $status, $roundup]) {
        DB::table('pos_payments')->insert([
            'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => $method,
            'amount' => $amount, 'status' => $status, 'pending_reconciliation' => false,
            'roundup_amount' => $roundup, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $row = $this->getJson('/admin/api/v1/orders')->assertOk()->json('data.0');

    expect($row['tenders'])->toHaveCount(2)
        ->and($row['tenders'][0]['method'])->toBe('cash')
        ->and($row['tenders'][0]['roundup'])->toBeNull()
        ->and($row['tenders'][1]['method'])->toBe('card')
        ->and($row['tenders'][1]['amount'])->toBe('5.000')
        ->and($row['tenders'][1]['roundup'])->toBe('0.500');
});

it('filters orders by company', function (): void {
    actingAsSalesAdmin($this);

    $c1 = Company::factory()->create();
    $b1 = Branch::factory()->create(['company_id' => $c1->id]);
    $c2 = Company::factory()->create();
    $b2 = Branch::factory()->create(['company_id' => $c2->id]);

    makeSalesOrderRow($c1->id, $b1->id, ['opened_at' => '2026-06-15 10:00:00']);
    makeSalesOrderRow($c2->id, $b2->id, ['opened_at' => '2026-06-15 12:00:00']);

    $res = $this->getJson("/admin/api/v1/orders?from=2026-06-01&to=2026-06-30&company_uuid={$c1->uuid}")->assertOk();

    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0.company.uuid'))->toBe($c1->uuid);
});
