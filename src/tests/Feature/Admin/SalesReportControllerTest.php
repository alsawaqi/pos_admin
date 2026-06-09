<?php

declare(strict_types=1);

/**
 * Admin platform Sales Report endpoint (v2 #16 + #19).
 *
 * Covers: permission gate, platform-wide headline + top merchants,
 * per-merchant scoping (company_uuid → by_branch), the daily trend,
 * order-type + payment-method breakdowns, and refund handling.
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

function actingAsReportsAdmin(\Tests\TestCase $test, string $role = PlatformRole::SuperAdmin->value): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

/** @param array<string, mixed> $attrs */
function makeAdminSalesOrder(int $companyId, int $branchId, array $attrs): int
{
    return (int) DB::table('pos_orders')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(),
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'order_type' => 'quick',
        'source' => 'main_pos',
        'status' => 'paid',
        'subtotal' => '5.000',
        'discount_total' => '0.000',
        'tax_total' => '0.000',
        'grand_total' => '5.000',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

it('is gated under reports.view', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->getJson('/admin/api/v1/sales-report')->assertForbidden();
});

it('aggregates platform-wide headline + ranks top merchants', function (): void {
    actingAsReportsAdmin($this);

    $c1 = Company::factory()->create(['name' => 'Alpha Co']);
    $b1 = Branch::factory()->create(['company_id' => $c1->id]);
    $c2 = Company::factory()->create(['name' => 'Beta Co']);
    $b2 = Branch::factory()->create(['company_id' => $c2->id]);

    makeAdminSalesOrder($c1->id, $b1->id, ['grand_total' => '80.000', 'opened_at' => '2026-06-15 10:00:00']);
    makeAdminSalesOrder($c1->id, $b1->id, ['grand_total' => '20.000', 'opened_at' => '2026-06-16 10:00:00']);
    makeAdminSalesOrder($c2->id, $b2->id, ['grand_total' => '30.000', 'opened_at' => '2026-06-15 11:00:00']);
    // Outside the window — excluded.
    makeAdminSalesOrder($c2->id, $b2->id, ['grand_total' => '999.000', 'opened_at' => '2026-05-01 11:00:00']);

    $data = $this->getJson('/admin/api/v1/sales-report?from=2026-06-01&to=2026-06-30')->assertOk()->json('data');

    expect($data['headline']['gross_sales'])->toBe('130.000'); // 80 + 20 + 30
    expect($data['headline']['order_count'])->toBe(3);
    expect($data['top_merchants'])->toHaveCount(2);
    expect($data['top_merchants'][0]['company_name'])->toBe('Alpha Co');
    expect($data['top_merchants'][0]['gross'])->toBe('100.000');
    expect($data['top_merchants'][1]['company_name'])->toBe('Beta Co');
    // Platform view carries no by_branch breakdown.
    expect($data['by_branch'])->toBe([]);
});

it('scopes to one merchant and breaks down by branch', function (): void {
    actingAsReportsAdmin($this);

    $c1 = Company::factory()->create(['name' => 'Alpha Co']);
    $b1 = Branch::factory()->create(['company_id' => $c1->id, 'name' => 'Downtown']);
    $b1b = Branch::factory()->create(['company_id' => $c1->id, 'name' => 'Airport']);
    $c2 = Company::factory()->create(['name' => 'Beta Co']);
    $b2 = Branch::factory()->create(['company_id' => $c2->id]);

    makeAdminSalesOrder($c1->id, $b1->id, ['grand_total' => '40.000', 'opened_at' => '2026-06-15 10:00:00']);
    makeAdminSalesOrder($c1->id, $b1b->id, ['grand_total' => '10.000', 'opened_at' => '2026-06-15 11:00:00']);
    makeAdminSalesOrder($c2->id, $b2->id, ['grand_total' => '500.000', 'opened_at' => '2026-06-15 12:00:00']);

    $data = $this->getJson("/admin/api/v1/sales-report?from=2026-06-01&to=2026-06-30&company_uuid={$c1->uuid}")
        ->assertOk()->json('data');

    expect($data['headline']['gross_sales'])->toBe('50.000'); // only Alpha
    expect($data['top_merchants'])->toBe([]);
    expect($data['by_branch'])->toHaveCount(2);
    expect($data['by_branch'][0]['branch_name'])->toBe('Downtown');
    expect($data['by_branch'][0]['gross'])->toBe('40.000');
});

it('builds a zero-filled daily trend across the window', function (): void {
    actingAsReportsAdmin($this);

    $c1 = Company::factory()->create();
    $b1 = Branch::factory()->create(['company_id' => $c1->id]);

    makeAdminSalesOrder($c1->id, $b1->id, ['grand_total' => '12.000', 'opened_at' => '2026-06-03 10:00:00']);

    $trend = $this->getJson('/admin/api/v1/sales-report?from=2026-06-01&to=2026-06-05')
        ->assertOk()->json('data.sales_trend');

    expect($trend)->toHaveCount(5);
    expect($trend[0]['date'])->toBe('2026-06-01');
    expect($trend[0]['gross'])->toBe('0.000');
    expect($trend[2]['date'])->toBe('2026-06-03');
    expect($trend[2]['gross'])->toBe('12.000');
    expect($trend[2]['count'])->toBe(1);
});

it('breaks down by order type and payment method', function (): void {
    actingAsReportsAdmin($this);

    $c1 = Company::factory()->create();
    $b1 = Branch::factory()->create(['company_id' => $c1->id]);

    $o1 = makeAdminSalesOrder($c1->id, $b1->id, ['grand_total' => '15.000', 'order_type' => 'dine_in', 'opened_at' => '2026-06-15 10:00:00']);
    makeAdminSalesOrder($c1->id, $b1->id, ['grand_total' => '5.000', 'order_type' => 'quick', 'opened_at' => '2026-06-15 11:00:00']);

    DB::table('pos_payments')->insert([
        'uuid' => (string) Str::uuid(),
        'order_id' => $o1,
        'method' => 'card',
        'amount' => '15.000',
        'status' => 'success',
        'captured_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $data = $this->getJson('/admin/api/v1/sales-report?from=2026-06-01&to=2026-06-30')->assertOk()->json('data');

    $types = collect($data['by_order_type'])->keyBy('type');
    expect($types['dine_in']['gross'])->toBe('15.000');
    expect($types['quick']['gross'])->toBe('5.000');

    expect($data['by_payment_method'])->toHaveCount(1);
    expect($data['by_payment_method'][0]['method'])->toBe('card');
    expect($data['by_payment_method'][0]['amount'])->toBe('15.000');
});

it('excludes refunds from gross but reports refunds_total', function (): void {
    actingAsReportsAdmin($this);

    $c1 = Company::factory()->create();
    $b1 = Branch::factory()->create(['company_id' => $c1->id]);

    makeAdminSalesOrder($c1->id, $b1->id, ['grand_total' => '50.000', 'opened_at' => '2026-06-15 10:00:00']);
    makeAdminSalesOrder($c1->id, $b1->id, ['grand_total' => '8.000', 'status' => 'refunded', 'opened_at' => '2026-06-15 11:00:00']);

    $data = $this->getJson('/admin/api/v1/sales-report?from=2026-06-01&to=2026-06-30')->assertOk()->json('data');

    expect($data['headline']['gross_sales'])->toBe('50.000');
    expect($data['headline']['refunds_total'])->toBe('8.000');
    expect($data['headline']['refund_count'])->toBe(1);
});
