<?php

declare(strict_types=1);

/**
 * Feature tests for the Admin Dashboard summary endpoint
 * (Slim Sprint 2 / blueprint §4.8).
 *
 * Covers:
 *   - Empty-state safety: every per-status map carries every enum
 *     case zeroed out so the frontend doesn't have to defend
 *     against undefined keys.
 *   - Counts: totals + per-status splits + unassigned count line
 *     up with the seed data.
 *   - Recent merchants: returns ≤5, newest-first.
 *   - Recent activity: returns ≤20 audit rows in the same shape
 *     as the AuditLogResource.
 *   - Fleet health: online / offline_assigned / low_battery counts
 *     derived from the heartbeat columns.
 *   - Round-up today + reconciliation queue: present only for
 *     reports.view holders; today-boundary + status filtering.
 *   - Permission: any authenticated admin can read it — no
 *     specific permission required (matches the route definition).
 *     401 when unauthenticated.
 */

use App\Enums\CompanyStatus;
use App\Enums\DeviceStatus;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

/**
 * Helper — log in as an admin with the given seeded role. Reused
 * from the convention in DevicesControllerTest / AuditLogsControllerTest.
 */
function actingAsDashboardRole(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

/**
 * Helper — seed a pos_roundup_donations row. Mirrors the column set
 * pos_api's donation.record handler writes (same shape the RoundUp
 * report test seeds).
 */
function dashSeedRoundup(int $companyId, string $amount, string $status, \Illuminate\Support\Carbon $occurredAt): void
{
    DB::table('pos_roundup_donations')->insert([
        'uuid' => (string) Str::uuid(),
        'company_id' => $companyId,
        'branch_id' => 10,
        'device_id' => 1,
        'order_id' => random_int(1, 1_000_000),
        'payment_id' => random_int(1, 1_000_000),
        'amount' => $amount,
        'status' => $status,
        'source' => 'pos_roundup',
        'occurred_at' => $occurredAt,
        'created_at' => $occurredAt,
        'updated_at' => $occurredAt,
    ]);
}

/**
 * Helper — seed a paid order + one payment row. pos_payments FKs to
 * pos_orders, so a real parent graph is required (same approach as
 * BankReconciliationTest).
 */
function dashSeedPayment(string $amount, bool $pendingRecon): void
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    $orderId = DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
        'subtotal' => $amount, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => $amount,
        'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('pos_payments')->insert([
        'uuid' => (string) Str::uuid(),
        'order_id' => $orderId,
        'method' => 'card',
        'amount' => $amount,
        'status' => $pendingRecon ? 'pending_reconciliation' : 'success',
        'pending_reconciliation' => $pendingRecon,
        'captured_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ===================== EMPTY-STATE SAFETY ============================

it('returns zeroed counts when there is no data', function (): void {
    actingAsDashboardRole($this, PlatformRole::Support->value);

    $response = $this->getJson('/admin/api/v1/dashboard/summary')
        ->assertOk();

    $payload = $response->json('data');

    expect($payload['companies']['total'])->toBe(0);
    expect($payload['branches']['total'])->toBe(0);
    expect($payload['devices']['total'])->toBe(0);
    expect($payload['devices']['unassigned'])->toBe(0);
    expect($payload['devices']['online'])->toBe(0);
    expect($payload['devices']['offline_assigned'])->toBe(0);
    expect($payload['devices']['low_battery'])->toBe(0);
    expect($payload['recent_merchants'])->toBe([]);
    expect($payload['recent_activity'])->toBe([]);

    // Every CompanyStatus / DeviceStatus key must be present even
    // when zero — keeps the frontend's lookup tables stable.
    foreach (CompanyStatus::cases() as $case) {
        expect($payload['companies']['by_status'])->toHaveKey($case->value);
        expect($payload['companies']['by_status'][$case->value])->toBe(0);
    }
    foreach (DeviceStatus::cases() as $case) {
        expect($payload['devices']['by_status'])->toHaveKey($case->value);
        expect($payload['devices']['by_status'][$case->value])->toBe(0);
    }
});

// =========================== COUNTS ==================================

it('totals companies and splits by status', function (): void {
    actingAsDashboardRole($this, PlatformRole::Support->value);

    // 2 active, 1 onboarding, 1 suspended → total 4.
    Company::factory()->count(2)->active()->create();
    Company::factory()->create(['status' => CompanyStatus::Onboarding]);
    Company::factory()->suspended()->create();

    $response = $this->getJson('/admin/api/v1/dashboard/summary')->assertOk();
    $companies = $response->json('data.companies');

    expect($companies['total'])->toBe(4);
    expect($companies['by_status']['active'])->toBe(2);
    expect($companies['by_status']['onboarding'])->toBe(1);
    expect($companies['by_status']['suspended'])->toBe(1);
    expect($companies['by_status']['inactive'])->toBe(0);
});

it('counts devices total + by status + unassigned separately', function (): void {
    actingAsDashboardRole($this, PlatformRole::Support->value);

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();

    // 2 unassigned registered, 1 assigned, 1 active. Unassigned
    // count tracks branch_id IS NULL — that's 2.
    Device::factory()->count(2)->create([
        'company_id' => null,
        'branch_id' => null,
        'status' => DeviceStatus::Registered,
    ]);
    Device::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => DeviceStatus::Assigned,
    ]);
    Device::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => DeviceStatus::Active,
    ]);

    $devices = $this->getJson('/admin/api/v1/dashboard/summary')->assertOk()->json('data.devices');

    expect($devices['total'])->toBe(4);
    expect($devices['by_status']['registered'])->toBe(2);
    expect($devices['by_status']['assigned'])->toBe(1);
    expect($devices['by_status']['active'])->toBe(1);
    expect($devices['by_status']['inactive'])->toBe(0);
    expect($devices['by_status']['blocked'])->toBe(0);
    expect($devices['unassigned'])->toBe(2);
});

// ======================== FLEET HEALTH ===============================

it('derives online, offline-assigned and low-battery counts from heartbeats', function (): void {
    actingAsDashboardRole($this, PlatformRole::Support->value);

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $assigned = ['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => DeviceStatus::Active];

    // Online: heartbeat 1 minute ago (inside the 5-minute window).
    Device::factory()->create($assigned + ['last_seen_at' => now()->subMinute(), 'last_battery' => 85]);
    // Offline-but-assigned: stale heartbeat (2 hours ago).
    Device::factory()->create($assigned + ['last_seen_at' => now()->subHours(2), 'last_battery' => 50]);
    // Offline-but-assigned: NEVER seen (null last_seen_at counts as offline).
    Device::factory()->create($assigned + ['last_seen_at' => null]);
    // Unassigned shelf device with no heartbeat — offline but NOT alarming.
    Device::factory()->create([
        'company_id' => null, 'branch_id' => null,
        'status' => DeviceStatus::Registered, 'last_seen_at' => null,
    ]);
    // Low battery: online AND below the 20% threshold (counted in both).
    Device::factory()->create($assigned + ['last_seen_at' => now()->subMinute(), 'last_battery' => 15]);

    $devices = $this->getJson('/admin/api/v1/dashboard/summary')->assertOk()->json('data.devices');

    expect($devices['online'])->toBe(2);          // 1-min ago × 2 (85% + 15% battery)
    expect($devices['offline_assigned'])->toBe(2); // stale + never-seen (shelf device excluded)
    expect($devices['low_battery'])->toBe(1);      // only the 15% one; nulls excluded
});

// ============== ROUND-UP TODAY + RECON QUEUE (gated) =================

it('reports today\'s successful round-up donations only', function (): void {
    actingAsDashboardRole($this, PlatformRole::FinanceViewer->value);
    $company = Company::factory()->create();

    dashSeedRoundup($company->id, '0.200', 'success', now());
    dashSeedRoundup($company->id, '0.300', 'success', now());
    // Pending + failed today: excluded — not money actually collected.
    dashSeedRoundup($company->id, '0.100', 'pending', now());
    dashSeedRoundup($company->id, '0.050', 'fail', now());
    // Successful but YESTERDAY: outside the today window.
    dashSeedRoundup($company->id, '9.000', 'success', now()->subDay());

    $roundup = $this->getJson('/admin/api/v1/dashboard/summary')->assertOk()->json('data.roundup_today');

    expect($roundup['total'])->toBe('0.500');
    expect($roundup['count'])->toBe(2);
});

it('counts and sums payments pending reconciliation', function (): void {
    actingAsDashboardRole($this, PlatformRole::FinanceViewer->value);

    dashSeedPayment('0.500', true);
    dashSeedPayment('1.500', true);
    // Already-settled payment: not in the queue.
    dashSeedPayment('99.000', false);

    $recon = $this->getJson('/admin/api/v1/dashboard/summary')->assertOk()->json('data.reconciliation_pending');

    expect($recon['count'])->toBe(2);
    expect($recon['amount'])->toBe('2.000');
});

it('omits the money tiles for admins without reports.view', function (): void {
    // Support has no reports.view → the ungated base payload still
    // loads but the round-up + recon keys must be absent entirely.
    actingAsDashboardRole($this, PlatformRole::Support->value);

    $payload = $this->getJson('/admin/api/v1/dashboard/summary')->assertOk()->json('data');

    expect($payload)->not->toHaveKey('roundup_today');
    expect($payload)->not->toHaveKey('reconciliation_pending');
});

it('includes zeroed money tiles for reports.view holders with no data', function (): void {
    actingAsDashboardRole($this, PlatformRole::FinanceViewer->value);

    $payload = $this->getJson('/admin/api/v1/dashboard/summary')->assertOk()->json('data');

    expect($payload['roundup_today'])->toBe(['total' => '0.000', 'count' => 0]);
    expect($payload['reconciliation_pending'])->toBe(['count' => 0, 'amount' => '0.000']);
});

// ====================== RECENT MERCHANTS =============================

it('returns recent merchants newest-first capped at 5', function (): void {
    actingAsDashboardRole($this, PlatformRole::Support->value);

    // Create 7 companies in known created_at order so the cap +
    // ordering both get exercised.
    foreach (range(1, 7) as $i) {
        Company::factory()->create([
            'name' => "Company {$i}",
            'created_at' => now()->subDays(10 - $i),
        ]);
    }

    $rows = $this->getJson('/admin/api/v1/dashboard/summary')
        ->assertOk()
        ->json('data.recent_merchants');

    expect(count($rows))->toBe(5);
    // Newest first → Company 7 then 6 then 5 then 4 then 3.
    expect($rows[0]['name'])->toBe('Company 7');
    expect($rows[4]['name'])->toBe('Company 3');
});

// ====================== RECENT ACTIVITY ==============================

it('returns recent activity newest-first capped at 20', function (): void {
    actingAsDashboardRole($this, PlatformRole::Support->value);

    // 25 audit rows, oldest first by created_at.
    foreach (range(1, 25) as $i) {
        AuditLog::query()->create([
            'event' => "test.event_{$i}",
            'created_at' => now()->subMinutes(30 - $i),
        ]);
    }

    $activity = $this->getJson('/admin/api/v1/dashboard/summary')
        ->assertOk()
        ->json('data.recent_activity');

    expect(count($activity))->toBe(20);
    // Newest is event_25; oldest in the window is event_6.
    expect($activity[0]['event'])->toBe('test.event_25');
    expect($activity[19]['event'])->toBe('test.event_6');
});

// =========================== PERMISSION ==============================

it('is reachable by any seeded role (no specific permission required)', function (): void {
    // Walk every seeded role and assert each gets a 200. The
    // dashboard is the landing page for everyone, so even the
    // most restricted role (Finance Viewer) must reach it.
    foreach (PlatformRole::cases() as $role) {
        actingAsDashboardRole($this, $role->value);
        $this->getJson('/admin/api/v1/dashboard/summary')->assertOk();
    }
});

it('requires authentication', function (): void {
    $this->getJson('/admin/api/v1/dashboard/summary')->assertUnauthorized();
});

it('is reachable by a role with no platform permissions at all', function (): void {
    // Even a role with the empty permission set still gets in —
    // the dashboard route is intentionally unguarded so the
    // landing page never 403s anyone.
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    Role::query()->firstOrCreate([
        'name' => 'dashboard_zero_perms',
        'guard_name' => 'web',
        'team_id' => TenantContext::PLATFORM_TEAM_ID,
    ]);
    $user->assignRole('dashboard_zero_perms');
    $this->actingAs($user);

    $this->getJson('/admin/api/v1/dashboard/summary')->assertOk();
});

it('omits recent_merchants / recent_activity for a role missing those permissions', function (): void {
    // The dashboard surfaces a subset of exactly the data the Merchants and
    // Audit Log pages gate, so a custom role with merchants.view / audit_logs.view
    // revoked must not read those rows off the landing payload — while still
    // reaching the ungated counts (200, not 403).
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    Role::query()->firstOrCreate([
        'name' => 'dashboard_no_reads',
        'guard_name' => 'web',
        'team_id' => TenantContext::PLATFORM_TEAM_ID,
    ]);
    $user->assignRole('dashboard_no_reads');
    $this->actingAs($user);

    $payload = $this->getJson('/admin/api/v1/dashboard/summary')->assertOk()->json('data');

    expect($payload)->toHaveKey('companies')            // ungated counts still present
        ->and($payload)->not->toHaveKey('recent_merchants')
        ->and($payload)->not->toHaveKey('recent_activity');
});
