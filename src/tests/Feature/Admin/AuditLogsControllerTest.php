<?php

declare(strict_types=1);

/**
 * Feature tests for the Admin Audit Log endpoints
 * (Sprint 1.5 / blueprint §4.7).
 *
 * Covers:
 *   - LIST: pagination + structure, every filter (event substring,
 *     target_type, company_uuid, branch_uuid, actor_id, date range)
 *     works in isolation, gracefully handles unknown values.
 *   - PERMISSION: a role without AuditLogsView is forbidden;
 *     every seeded role today has the permission, so we use a
 *     home-grown role with zero permissions to assert the gate.
 *   - EXPORT: streams a text/csv response with the BOM + header
 *     row + one body row per matched audit entry; reuses the same
 *     filter parsing so the CSV matches the visible table.
 *
 * Rows are written directly via {@see AuditLog::query()->create()}
 * rather than a factory because the model is intentionally fillable
 * for the write path but immutable for updates (see the model's
 * booted() guard). There's no AuditLogFactory in the project today.
 */

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\Carbon;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Spatie permission rows + the five platform roles so the
    // role-based test users have permissions to compare against.
    $this->seed(PlatformRoleSeeder::class);
});

/**
 * Helper — log in as an admin with the given seeded platform role.
 * Mirrors the convention used in DevicesControllerTest +
 * MerchantsControllerTest.
 */
function actingAsAuditRole(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

/**
 * Helper — write one audit row inline. Bypasses
 * WriteAuditLogAction so the test can pin created_at to a precise
 * timestamp (action sets it via DB default, which is now()).
 *
 * @param  array<string, mixed>  $attrs
 */
function makeAuditRow(array $attrs): AuditLog
{
    /** @var AuditLog $row */
    $row = AuditLog::query()->create(array_merge([
        'event' => 'system.test',
        'ip_address' => '203.0.113.10',
        'user_agent' => 'PhpUnit/1.0',
    ], $attrs));

    // Allow the test to set created_at without tripping the
    // updated_at column (the model doesn't have one — UPDATED_AT
    // = null) — Eloquent's create() honours timestamps set in the
    // attributes array, but a sanity-check refresh keeps the
    // returned instance in sync with what's actually persisted.
    if (isset($attrs['created_at'])) {
        $row->forceFill(['created_at' => $attrs['created_at']])->saveQuietly();
    }

    return $row->fresh();
}

// =========================== LIST: BASICS ===========================

it('lists audit log entries for users with audit_logs.view permission', function (): void {
    actingAsAuditRole($this, PlatformRole::Support->value);

    // Three baseline entries — the index should paginate them all
    // on a single page since per_page defaults to 25.
    $company = Company::factory()->create();
    foreach (['company.created', 'company.updated', 'branch.created'] as $event) {
        makeAuditRow([
            'event' => $event,
            'company_id' => $company->id,
        ]);
    }

    $this->getJson('/admin/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'event', 'occurred_at', 'target_type', 'target_id', 'actor', 'company', 'branch']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'links',
        ])
        ->assertJsonCount(3, 'data');
});

it('forbids list access without audit_logs.view permission', function (): void {
    // Every seeded platform role today has the permission, so we
    // create an ad-hoc role with no permissions to test the gate.
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

    Role::query()->firstOrCreate([
        'name' => 'no_perms_role',
        'guard_name' => 'web',
        'team_id' => TenantContext::PLATFORM_TEAM_ID,
    ]);
    $user->assignRole('no_perms_role');
    $this->actingAs($user);

    $this->getJson('/admin/api/v1/audit-logs')->assertForbidden();
});

it('requires authentication on the list endpoint', function (): void {
    // No actingAs — the auth middleware should bounce the request
    // with a 401 JSON response (per the SPA fallback route).
    $this->getJson('/admin/api/v1/audit-logs')->assertUnauthorized();
});

// =========================== LIST: FILTERS ==========================

it('filters by event substring', function (): void {
    actingAsAuditRole($this, PlatformRole::Support->value);

    makeAuditRow(['event' => 'device.assigned']);
    makeAuditRow(['event' => 'device.unassigned']);
    makeAuditRow(['event' => 'company.created']);

    $response = $this->getJson('/admin/api/v1/audit-logs?event=device.')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    foreach ($response->json('data') as $row) {
        expect($row['event'])->toStartWith('device.');
    }
});

it('filters by target_type via the short-name map', function (): void {
    actingAsAuditRole($this, PlatformRole::Support->value);

    $company = Company::factory()->create();
    $device = Device::factory()->create();

    makeAuditRow([
        'event' => 'company.created',
        'auditable_type' => Company::class,
        'auditable_id' => $company->id,
        'company_id' => $company->id,
    ]);
    makeAuditRow([
        'event' => 'device.registered',
        'auditable_type' => Device::class,
        'auditable_id' => $device->id,
    ]);

    $this->getJson('/admin/api/v1/audit-logs?target_type=device')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.target_type', 'device');
});

it('returns no rows for an unknown target_type instead of returning everything', function (): void {
    // Defensive check — a stale front-end shouldn't be able to
    // dump the entire audit log by sending a garbage type value.
    actingAsAuditRole($this, PlatformRole::Support->value);

    makeAuditRow(['event' => 'company.created']);

    $this->getJson('/admin/api/v1/audit-logs?target_type=not_a_real_type')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters by company_uuid by resolving uuid to id server-side', function (): void {
    actingAsAuditRole($this, PlatformRole::Support->value);

    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    makeAuditRow(['event' => 'company.created', 'company_id' => $companyA->id]);
    makeAuditRow(['event' => 'company.created', 'company_id' => $companyA->id]);
    makeAuditRow(['event' => 'company.created', 'company_id' => $companyB->id]);

    $this->getJson('/admin/api/v1/audit-logs?company_uuid='.$companyA->uuid)
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('returns zero rows for an unknown company_uuid (instead of unfiltered everything)', function (): void {
    actingAsAuditRole($this, PlatformRole::Support->value);

    makeAuditRow(['event' => 'company.created']);

    $this->getJson('/admin/api/v1/audit-logs?company_uuid=00000000-0000-0000-0000-000000000000')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters by branch_uuid', function (): void {
    actingAsAuditRole($this, PlatformRole::Support->value);

    $company = Company::factory()->create();
    $branchA = Branch::factory()->for($company)->create();
    $branchB = Branch::factory()->for($company)->create();

    makeAuditRow(['event' => 'branch.updated', 'company_id' => $company->id, 'branch_id' => $branchA->id]);
    makeAuditRow(['event' => 'branch.updated', 'company_id' => $company->id, 'branch_id' => $branchB->id]);

    $this->getJson('/admin/api/v1/audit-logs?branch_uuid='.$branchA->uuid)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.branch.uuid', $branchA->uuid);
});

it('filters by actor_id', function (): void {
    $support = actingAsAuditRole($this, PlatformRole::Support->value);
    $other = User::factory()->create();

    makeAuditRow(['event' => 'company.created', 'actor_user_id' => $support->id]);
    makeAuditRow(['event' => 'company.created', 'actor_user_id' => $other->id]);

    $this->getJson('/admin/api/v1/audit-logs?actor_id='.$support->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.actor.id', $support->id);
});

it('filters by date range with inclusive boundaries on bare dates', function (): void {
    actingAsAuditRole($this, PlatformRole::Support->value);

    // Three rows spread across three days. Filtering 2026-05-20 to
    // 2026-05-21 (bare dates) should return rows 2 and 3 — the
    // upper bound snaps to end-of-day so the row at 23:30 is
    // included.
    makeAuditRow(['event' => 'company.created', 'created_at' => Carbon::parse('2026-05-19 10:00:00')]);
    makeAuditRow(['event' => 'company.created', 'created_at' => Carbon::parse('2026-05-20 10:00:00')]);
    makeAuditRow(['event' => 'company.created', 'created_at' => Carbon::parse('2026-05-21 23:30:00')]);

    $this->getJson('/admin/api/v1/audit-logs?from=2026-05-20&to=2026-05-21')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('paginates with default per_page=25 and caps per_page at 100', function (): void {
    actingAsAuditRole($this, PlatformRole::Support->value);

    // 26 rows so we straddle the default page size.
    foreach (range(1, 26) as $_) {
        makeAuditRow(['event' => 'company.created']);
    }

    $this->getJson('/admin/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.total', 26)
        ->assertJsonPath('meta.last_page', 2);

    // Asking for per_page=500 must be capped to 100 — the rows on
    // page 1 should still cover all 26.
    $this->getJson('/admin/api/v1/audit-logs?per_page=500')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100)
        ->assertJsonCount(26, 'data');
});

it('orders results newest-first by created_at', function (): void {
    actingAsAuditRole($this, PlatformRole::Support->value);

    $oldest = makeAuditRow(['event' => 'a.old', 'created_at' => Carbon::parse('2026-01-01')]);
    $middle = makeAuditRow(['event' => 'a.middle', 'created_at' => Carbon::parse('2026-03-01')]);
    $newest = makeAuditRow(['event' => 'a.new', 'created_at' => Carbon::parse('2026-05-01')]);

    $response = $this->getJson('/admin/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    expect($response->json('data.0.id'))->toBe($newest->id);
    expect($response->json('data.1.id'))->toBe($middle->id);
    expect($response->json('data.2.id'))->toBe($oldest->id);
});

// =========================== EXPORT =================================

it('streams a CSV with header + one row per matching entry', function (): void {
    actingAsAuditRole($this, PlatformRole::FinanceViewer->value);

    $company = Company::factory()->create();
    makeAuditRow([
        'event' => 'company.created',
        'company_id' => $company->id,
        'auditable_type' => Company::class,
        'auditable_id' => $company->id,
    ]);
    makeAuditRow([
        'event' => 'company.updated',
        'company_id' => $company->id,
        'auditable_type' => Company::class,
        'auditable_id' => $company->id,
    ]);

    $response = $this->get('/admin/api/v1/audit-logs/export.csv')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $body = $response->streamedContent();

    // BOM is the first three bytes so Excel detects UTF-8.
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");
    // Header row is present.
    expect($body)->toContain('id,occurred_at_utc,event,actor_id,actor_name');
    // Both events appear in the body.
    expect($body)->toContain('company.created');
    expect($body)->toContain('company.updated');
});

it('applies the same filters to the CSV export as to the visible table', function (): void {
    actingAsAuditRole($this, PlatformRole::FinanceViewer->value);

    makeAuditRow(['event' => 'device.assigned']);
    makeAuditRow(['event' => 'device.unassigned']);
    makeAuditRow(['event' => 'company.created']);

    $body = $this->get('/admin/api/v1/audit-logs/export.csv?event=device.')
        ->assertOk()
        ->streamedContent();

    // Two matching rows + the header line (3 lines total minus the
    // trailing newline). Counting CSV "device." occurrences is the
    // cleanest assertion — header contains `device` only inside
    // column names, so checking `device.assigned` + `device.unassigned`
    // is unambiguous.
    expect($body)->toContain('device.assigned');
    expect($body)->toContain('device.unassigned');
    expect($body)->not->toContain('company.created');
});

it('forbids CSV export without audit_logs.view permission', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    Role::query()->firstOrCreate([
        'name' => 'no_perms_role_export',
        'guard_name' => 'web',
        'team_id' => TenantContext::PLATFORM_TEAM_ID,
    ]);
    $user->assignRole('no_perms_role_export');
    $this->actingAs($user);

    $this->get('/admin/api/v1/audit-logs/export.csv')->assertForbidden();
});
