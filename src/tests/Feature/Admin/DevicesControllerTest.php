<?php

declare(strict_types=1);

/**
 * Feature tests for the Devices admin endpoints (Sprint 1.2 /
 * blueprint §4.4). Covers:
 *
 *   - Register: success path, validation gating, role gating.
 *   - List: pagination + filters work, permission gate enforced.
 *   - Show: returns assignment history, 404 on unknown UUID.
 *   - Assign: opens a history row, closes prior history on reassign,
 *             pushes geofence override down to the branch row,
 *             rejects branch from a different company.
 *   - Unassign: closes the open history row, demotes status.
 *   - Cross-tenant isolation: the audit-log + history tables stay
 *     queryable from admin scope (admin is omniscient by design,
 *     §9.11.6) but every endpoint still demands the right permission.
 *
 * Every test uses RefreshDatabase so the renamed `pos_*` tables get
 * built fresh from the migration chain on each run.
 */

use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceAssignmentHistory;
use App\Models\DeviceMake;
use App\Models\DeviceModel;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Seeds the spatie permission rows and the five platform roles
    // (super_admin, onboarding_officer, device_operations, support,
    // finance_viewer). Without this the role-based test users have
    // no permissions and every endpoint rejects them.
    $this->seed(PlatformRoleSeeder::class);
});

/**
 * Helper — creates a platform user, assigns them a role, and starts
 * acting as them. Mirrors the helper used in MerchantsControllerTest
 * so test bodies stay short.
 */
function actingAsDeviceRole(TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    // Spatie's "teams" feature is keyed on a numeric team id; the
    // platform-side roles all live under PLATFORM_TEAM_ID so we set
    // that explicitly before assigning.
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

/**
 * Helper — inserts a minimal `banks` row directly via the query
 * builder and returns its id, for use in the register-device test
 * payload. Mirrors the inline commission_profiles inserts elsewhere
 * in this file (no factory because the stub schema is intentionally
 * minimal — see 2026_05_26_010000_ensure_banks_stub.php).
 *
 * @param  array<string, mixed>  $overrides
 */
function makeTestBank(array $overrides = []): int
{
    return (int) DB::table('banks')->insertGetId(array_merge([
        'name' => 'Bank Muscat',
        'short_name' => 'BM',
        'swift_code' => 'BMUSOMRX',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

// ============================ LIST =================================

it('lists devices for users with devices.view permission', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    // Three baseline devices so the paginator has something to show.
    Device::factory()->count(3)->create();

    $this->getJson('/admin/api/v1/devices')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta', 'links'])
        ->assertJsonCount(3, 'data');
});

it('respects the unassigned filter on the list endpoint', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    // 2 unassigned devices + 1 assigned. The filter must return only the 2.
    Device::factory()->count(2)->create([
        'company_id' => null,
        'branch_id' => null,
    ]);

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    Device::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => DeviceStatus::Assigned,
    ]);

    $response = $this->getJson('/admin/api/v1/devices?unassigned=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Sanity: every returned row really is unassigned.
    foreach ($response->json('data') as $row) {
        expect($row['branch_id'])->toBeNull();
    }
});

it('forbids list access without devices.view permission', function (): void {
    // FinanceViewer has reports + audit perms but no devices perms.
    actingAsDeviceRole($this, PlatformRole::FinanceViewer->value);
    $this->getJson('/admin/api/v1/devices')->assertForbidden();
});

// ============================ REGISTER =============================

it('registers a device with full payload', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    // Sprint 1.4: make + model FKs replaced the free-text model
    // string. terminal_id + bank_id are NO LONGER captured here —
    // they move to the ASSIGN step (the terminal is issued against
    // the merchant's bank account). Registration keeps commission.
    $make = DeviceMake::factory()->create(['name' => 'Sunmi']);
    $model = DeviceModel::factory()->for($make, 'make')->create(['name' => 'P2 Mini']);
    $profileId = DB::table('commission_profiles')->insertGetId([
        'name' => 'Standard 80/20',
        'description' => 'Test profile',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'POS-9001-XYZ',
        'kiosk_id' => 'KIOSK-AAAA-99999',
        'commission_profile_id' => $profileId,
        'device_type' => DeviceType::FixedPos->value,
        'name' => 'Counter Terminal 1',
        'label' => 'POS-001',
        'make_id' => $make->id,
        'model_id' => $model->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.serial_number', 'POS-9001-XYZ')
        ->assertJsonPath('data.kiosk_id', 'KIOSK-AAAA-99999')
        // Registered devices have no terminal/bank yet — set at assign.
        ->assertJsonPath('data.terminal_id', null)
        ->assertJsonPath('data.device_type', 'fixed_pos')
        ->assertJsonPath('data.status', 'registered')
        ->assertJsonPath('data.make.name', 'Sunmi')
        ->assertJsonPath('data.model.name', 'P2 Mini')
        ->assertJsonPath('data.commission_profile.name', 'Standard 80/20');

    $this->assertDatabaseHas('pos_devices', [
        'serial_number' => 'POS-9001-XYZ',
        'kiosk_id' => 'KIOSK-AAAA-99999',
        'terminal_id' => null,
        'bank_id' => null,
        'commission_profile_id' => $profileId,
        'status' => DeviceStatus::Registered->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ]);
});

it('does not require terminal_id or bank_id at registration', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $make = DeviceMake::factory()->create();
    $model = DeviceModel::factory()->for($make, 'make')->create();
    $profileId = DB::table('commission_profiles')->insertGetId([
        'name' => 'Profile NoTerminal',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A payload WITHOUT terminal_id / bank_id must still register cleanly.
    $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'SN-POOL-1',
        'kiosk_id' => 'KID-POOL-1',
        'commission_profile_id' => $profileId,
        'device_type' => DeviceType::FixedPos->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ])->assertCreated();
});

it('rejects register with unknown commission_profile_id', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $make = DeviceMake::factory()->create();
    $model = DeviceModel::factory()->for($make, 'make')->create();

    $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'SN-PHANTOM',
        'kiosk_id' => 'KID-PHANTOM',
        'commission_profile_id' => 999_999,
        'device_type' => DeviceType::FixedPos->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['commission_profile_id']);
});

it('rejects register when model does not belong to make', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    // Two makes; the test submits make A's id with make B's model id.
    $makeA = DeviceMake::factory()->create();
    $makeB = DeviceMake::factory()->create();
    $modelOfB = DeviceModel::factory()->for($makeB, 'make')->create();

    // Also need a commission profile so the test isolates the
    // make/model cross-check rather than tripping on commission_profile_id.
    $profileId = DB::table('commission_profiles')->insertGetId([
        'name' => 'Profile Cross',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'CROSS-PAIR-001',
        'kiosk_id' => 'CROSS-PAIR-KID',
        'commission_profile_id' => $profileId,
        'device_type' => DeviceType::FixedPos->value,
        'make_id' => $makeA->id,
        'model_id' => $modelOfB->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['model_id']);
});

it('rejects register with duplicate serial', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);
    $make = DeviceMake::factory()->create();
    $model = DeviceModel::factory()->for($make, 'make')->create();
    $profileId = DB::table('commission_profiles')->insertGetId([
        'name' => 'Profile Dup',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Device::factory()->create(['serial_number' => 'SN-DUP', 'kiosk_id' => 'KID-DUP']);

    $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'SN-DUP',
        'kiosk_id' => 'KID-OTHER',
        'commission_profile_id' => $profileId,
        'device_type' => DeviceType::Handheld->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['serial_number']);
});

it('forbids register without devices.register permission', function (): void {
    // Support role can view devices but cannot register them.
    actingAsDeviceRole($this, PlatformRole::Support->value);
    $make = DeviceMake::factory()->create();
    $model = DeviceModel::factory()->for($make, 'make')->create();
    $profileId = DB::table('commission_profiles')->insertGetId([
        'name' => 'Profile Forbid',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'NEW-SERIAL',
        'kiosk_id' => 'NEW-KIOSK',
        'commission_profile_id' => $profileId,
        'device_type' => DeviceType::FixedPos->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ])->assertForbidden();
});

// ====================== BANK BINDING ================================
// terminal_id + bank_id moved from registration to ASSIGNMENT — their
// validation lives in the ASSIGN section below. The banks dropdown
// endpoint is still consumed (now by the Assign modal).

it('lists active banks at GET /admin/api/v1/banks for the dropdown', function (): void {
    // The Register Device page hits this endpoint to populate the
    // bank select. Inactive banks should be hidden by default.
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    makeTestBank(['name' => 'Bank Muscat', 'short_name' => 'BM']);
    makeTestBank(['name' => 'National Bank of Oman', 'short_name' => 'NBO']);
    makeTestBank(['name' => 'Retired Bank', 'is_active' => false]);

    $response = $this->getJson('/admin/api/v1/banks')->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('Bank Muscat', 'National Bank of Oman')
        ->and($names)->not->toContain('Retired Bank');
});

// ============================ SHOW =================================

it('returns device detail with assignment history embedded', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    // Set up: a registered device that has been (re)assigned twice.
    $device = Device::factory()->create();
    $company = Company::factory()->create();
    $branchA = Branch::factory()->for($company)->create(['name' => 'Branch A']);
    $branchB = Branch::factory()->for($company)->create(['name' => 'Branch B']);

    // First assignment (now closed).
    DeviceAssignmentHistory::query()->create([
        'device_id' => $device->id,
        'company_id' => $company->id,
        'branch_id' => $branchA->id,
        'assigned_at' => now()->subDays(3),
        'unassigned_at' => now()->subDays(1),
    ]);
    // Current assignment (open).
    DeviceAssignmentHistory::query()->create([
        'device_id' => $device->id,
        'company_id' => $company->id,
        'branch_id' => $branchB->id,
        'assigned_at' => now()->subDays(1),
    ]);

    $this->getJson("/admin/api/v1/devices/{$device->uuid}")
        ->assertOk()
        // Newest assignment surfaces first because the relation
        // orders by assigned_at DESC.
        ->assertJsonPath('data.assignment_history.0.branch.name', 'Branch B')
        ->assertJsonPath('data.assignment_history.0.unassigned_at', null)
        ->assertJsonPath('data.assignment_history.1.branch.name', 'Branch A');
});

it('returns 404 for unknown device uuid', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);
    $this->getJson('/admin/api/v1/devices/00000000-0000-0000-0000-000000000000')
        ->assertNotFound();
});

// ============================ ASSIGN ===============================

it('assigns a device with a terminal + bank and opens an assignment history row', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $device = Device::factory()->create();
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create([
        'geofence_radius_m' => 500,
    ]);
    $bankId = makeTestBank();

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'bank_id' => $bankId,
        'terminal_id' => 'TERM-ASSIGN-1',
    ])
        ->assertOk()
        ->assertJsonPath('data.branch_id', $branch->id)
        ->assertJsonPath('data.company_id', $company->id)
        ->assertJsonPath('data.status', 'assigned')
        // Terminal + bank are captured AT ASSIGN (not registration).
        ->assertJsonPath('data.terminal_id', 'TERM-ASSIGN-1')
        ->assertJsonPath('data.bank_id', $bankId);

    // Exactly one open history row should exist.
    expect(DeviceAssignmentHistory::query()
        ->where('device_id', $device->id)
        ->whereNull('unassigned_at')
        ->count())->toBe(1);
});

it('requires bank_id and terminal_id on assign', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $device = Device::factory()->create();
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();

    // company/branch present but terminal+bank omitted → 422.
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['bank_id', 'terminal_id']);
});

it('rejects a terminal_id already used within the same bank', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $bankId = makeTestBank();

    // An existing assigned device already holds TERM-DUP under this bank.
    Device::factory()->create([
        'bank_id' => $bankId,
        'terminal_id' => 'TERM-DUP',
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => DeviceStatus::Assigned,
    ]);

    $device = Device::factory()->create();
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'bank_id' => $bankId,
        'terminal_id' => 'TERM-DUP',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['terminal_id']);
});

it('allows the same terminal_id under a different bank', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $bankA = makeTestBank(['name' => 'Bank A', 'short_name' => 'BA']);
    $bankB = makeTestBank(['name' => 'Bank B', 'short_name' => 'BB']);

    // Bank A already issued TERM-SHARED.
    Device::factory()->create([
        'bank_id' => $bankA,
        'terminal_id' => 'TERM-SHARED',
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => DeviceStatus::Assigned,
    ]);

    // The same terminal under bank B is fine.
    $device = Device::factory()->create();
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'bank_id' => $bankB,
        'terminal_id' => 'TERM-SHARED',
    ])->assertOk()
        ->assertJsonPath('data.terminal_id', 'TERM-SHARED');
});

it('closes the prior history row when reassigning', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $device = Device::factory()->create();
    $companyA = Company::factory()->create();
    $branchA = Branch::factory()->for($companyA)->create();
    $companyB = Company::factory()->create();
    $branchB = Branch::factory()->for($companyB)->create();

    $bankId = makeTestBank();

    // First assignment
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $companyA->id,
        'branch_id' => $branchA->id,
        'bank_id' => $bankId,
        'terminal_id' => 'TERM-REASSIGN-A',
    ])->assertOk();

    // Reassignment to a different company/branch (new terminal too).
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $companyB->id,
        'branch_id' => $branchB->id,
        'bank_id' => $bankId,
        'terminal_id' => 'TERM-REASSIGN-B',
    ])->assertOk();

    // Two history rows total; exactly one is still open.
    expect(DeviceAssignmentHistory::query()->where('device_id', $device->id)->count())->toBe(2);
    expect(DeviceAssignmentHistory::query()->where('device_id', $device->id)->whereNull('unassigned_at')->count())->toBe(1);
});

it('rejects assigning to a branch that belongs to a different company', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $device = Device::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $branchA = Branch::factory()->for($companyA)->create();

    // company_id says B, branch_id belongs to A — must fail at the
    // Action layer (firstOrFail throws 404). Terminal+bank supplied so
    // the request passes validation and reaches the cross-check.
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $companyB->id,
        'branch_id' => $branchA->id,
        'bank_id' => makeTestBank(),
        'terminal_id' => 'TERM-XCOMPANY',
    ])->assertNotFound();
});

it('pushes a geofence radius override down to the branch on assign', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $device = Device::factory()->create();
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create(['geofence_radius_m' => 500]);

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'bank_id' => makeTestBank(),
        'terminal_id' => 'TERM-GEO',
        'geofence_radius_m' => 750,
    ])->assertOk();

    $this->assertDatabaseHas('pos_branches', [
        'id' => $branch->id,
        'geofence_radius_m' => 750,
    ]);
});

it('forbids assign without devices.assign permission', function (): void {
    actingAsDeviceRole($this, PlatformRole::Support->value);

    $device = Device::factory()->create();
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'bank_id' => makeTestBank(),
        'terminal_id' => 'TERM-FORBID-ASSIGN',
    ])->assertForbidden();
});

// ============================ UNASSIGN =============================

it('unassigns a device, clears its terminal/bank, and closes its open history row', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $device = Device::factory()->create();
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();

    // Assign first so there's something to unassign (terminal + bank set).
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'bank_id' => makeTestBank(),
        'terminal_id' => 'TERM-UNASSIGN',
    ])->assertOk();

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/unassign", [
        'reason' => 'Branch closed',
    ])
        ->assertOk()
        ->assertJsonPath('data.branch_id', null)
        ->assertJsonPath('data.company_id', null)
        ->assertJsonPath('data.status', 'registered')
        // Terminal + bank released back to the pool so the device can be
        // re-assigned to another merchant with a fresh terminal.
        ->assertJsonPath('data.terminal_id', null)
        ->assertJsonPath('data.bank_id', null);

    // No more open rows; the row that was open carries the reason.
    expect(DeviceAssignmentHistory::query()->where('device_id', $device->id)->whereNull('unassigned_at')->count())->toBe(0);
    $this->assertDatabaseHas('pos_device_assignments_history', [
        'device_id' => $device->id,
        'unassign_reason' => 'Branch closed',
    ]);
});

it('forbids unassign without devices.unassign permission', function (): void {
    actingAsDeviceRole($this, PlatformRole::Support->value);
    $device = Device::factory()->create();

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/unassign", [])
        ->assertForbidden();
});
