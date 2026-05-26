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
