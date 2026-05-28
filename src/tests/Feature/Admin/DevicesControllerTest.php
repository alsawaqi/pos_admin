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
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

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
function actingAsDeviceRole(\Tests\TestCase $test, string $role): User
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
    return (int) \DB::table('banks')->insertGetId(array_merge([
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
    // string. Sprint 1.4 follow-up adds terminal_id + commission
    // profile FK. Sprint 1.5 follow-up adds bank_id — all required.
    // Set up a catalogue row pair + a commission profile + a bank
    // for the test payload.
    $make = \App\Models\DeviceMake::factory()->create(['name' => 'Sunmi']);
    $model = \App\Models\DeviceModel::factory()->for($make, 'make')->create(['name' => 'P2 Mini']);
    $profileId = \DB::table('commission_profiles')->insertGetId([
        'name' => 'Standard 80/20',
        'description' => 'Test profile',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $bankId = makeTestBank(['name' => 'Bank Muscat']);

    $response = $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'POS-9001-XYZ',
        'kiosk_id' => 'KIOSK-AAAA-99999',
        'terminal_id' => 'TERM-9001',
        'commission_profile_id' => $profileId,
        'bank_id' => $bankId,
        'device_type' => DeviceType::FixedPos->value,
        'name' => 'Counter Terminal 1',
        'label' => 'POS-001',
        'make_id' => $make->id,
        'model_id' => $model->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.serial_number', 'POS-9001-XYZ')
        ->assertJsonPath('data.kiosk_id', 'KIOSK-AAAA-99999')
        ->assertJsonPath('data.terminal_id', 'TERM-9001')
        ->assertJsonPath('data.device_type', 'fixed_pos')
        ->assertJsonPath('data.status', 'registered')
        ->assertJsonPath('data.make.name', 'Sunmi')
        ->assertJsonPath('data.model.name', 'P2 Mini')
        ->assertJsonPath('data.commission_profile.name', 'Standard 80/20')
        // Bank object is exposed nested + the FK is also on the row.
        ->assertJsonPath('data.bank_id', $bankId)
        ->assertJsonPath('data.bank.name', 'Bank Muscat');

    $this->assertDatabaseHas('pos_devices', [
        'serial_number' => 'POS-9001-XYZ',
        'kiosk_id' => 'KIOSK-AAAA-99999',
        'terminal_id' => 'TERM-9001',
        'commission_profile_id' => $profileId,
        'bank_id' => $bankId,
        'status' => DeviceStatus::Registered->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ]);
});

it('rejects register with duplicate terminal_id', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $make = \App\Models\DeviceMake::factory()->create();
    $model = \App\Models\DeviceModel::factory()->for($make, 'make')->create();
    $profileId = \DB::table('commission_profiles')->insertGetId([
        'name' => 'Profile X',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Pre-existing device that already owns the terminal id.
    Device::factory()->create(['terminal_id' => 'TERM-DUP']);

    $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'SN-NEW',
        'kiosk_id' => 'KID-NEW',
        'terminal_id' => 'TERM-DUP',
        'commission_profile_id' => $profileId,
        'bank_id' => makeTestBank(['name' => 'NBO']),
        'device_type' => DeviceType::FixedPos->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['terminal_id']);
});

it('rejects register with unknown commission_profile_id', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $make = \App\Models\DeviceMake::factory()->create();
    $model = \App\Models\DeviceModel::factory()->for($make, 'make')->create();

    $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'SN-PHANTOM',
        'kiosk_id' => 'KID-PHANTOM',
        'terminal_id' => 'TERM-PHANTOM',
        'commission_profile_id' => 999_999,
        'bank_id' => makeTestBank(),
        'device_type' => DeviceType::FixedPos->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['commission_profile_id']);
});

it('rejects register when model does not belong to make', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    // Two makes; the test submits make A's id with make B's model id.
    $makeA = \App\Models\DeviceMake::factory()->create();
    $makeB = \App\Models\DeviceMake::factory()->create();
    $modelOfB = \App\Models\DeviceModel::factory()->for($makeB, 'make')->create();

    // Also need a commission profile so the test isolates the
    // make/model cross-check rather than tripping on commission_profile_id.
    $profileId = \DB::table('commission_profiles')->insertGetId([
        'name' => 'Profile Cross',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'CROSS-PAIR-001',
        'kiosk_id' => 'CROSS-PAIR-KID',
        'terminal_id' => 'TERM-CROSS',
        'commission_profile_id' => $profileId,
        'bank_id' => makeTestBank(),
        'device_type' => DeviceType::FixedPos->value,
        'make_id' => $makeA->id,
        'model_id' => $modelOfB->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['model_id']);
});

it('rejects register with duplicate serial', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);
    $make = \App\Models\DeviceMake::factory()->create();
    $model = \App\Models\DeviceModel::factory()->for($make, 'make')->create();
    $profileId = \DB::table('commission_profiles')->insertGetId([
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
        'terminal_id' => 'TERM-DUPTEST',
        'commission_profile_id' => $profileId,
        'bank_id' => makeTestBank(),
        'device_type' => DeviceType::Handheld->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['serial_number']);
});

it('forbids register without devices.register permission', function (): void {
    // Support role can view devices but cannot register them.
    actingAsDeviceRole($this, PlatformRole::Support->value);
    $make = \App\Models\DeviceMake::factory()->create();
    $model = \App\Models\DeviceModel::factory()->for($make, 'make')->create();
    $profileId = \DB::table('commission_profiles')->insertGetId([
        'name' => 'Profile Forbid',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'NEW-SERIAL',
        'kiosk_id' => 'NEW-KIOSK',
        'terminal_id' => 'TERM-FORBID',
        'commission_profile_id' => $profileId,
        'bank_id' => makeTestBank(),
        'device_type' => DeviceType::FixedPos->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ])->assertForbidden();
});

// ====================== BANK BINDING (Sprint 1.5) ===================

it('rejects register without bank_id', function (): void {
    // bank_id is required at registration — the bank reconciler
    // needs to know which API to call for a given terminal_id.
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $make = \App\Models\DeviceMake::factory()->create();
    $model = \App\Models\DeviceModel::factory()->for($make, 'make')->create();
    $profileId = \DB::table('commission_profiles')->insertGetId([
        'name' => 'Profile NoBank',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Intentionally OMIT bank_id from the payload.
    $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'SN-NOBANK',
        'kiosk_id' => 'KID-NOBANK',
        'terminal_id' => 'TERM-NOBANK',
        'commission_profile_id' => $profileId,
        'device_type' => DeviceType::FixedPos->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['bank_id']);
});

it('rejects register with unknown bank_id', function (): void {
    // Validation guards against an id that doesn't exist in the
    // charity-owned `banks` table.
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $make = \App\Models\DeviceMake::factory()->create();
    $model = \App\Models\DeviceModel::factory()->for($make, 'make')->create();
    $profileId = \DB::table('commission_profiles')->insertGetId([
        'name' => 'Profile UnknownBank',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/admin/api/v1/devices', [
        'serial_number' => 'SN-UNKBANK',
        'kiosk_id' => 'KID-UNKBANK',
        'terminal_id' => 'TERM-UNKBANK',
        'commission_profile_id' => $profileId,
        'bank_id' => 999_999, // Doesn't exist.
        'device_type' => DeviceType::FixedPos->value,
        'make_id' => $make->id,
        'model_id' => $model->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['bank_id']);
});

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

it('assigns a device and opens an assignment history row', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $device = Device::factory()->create();
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create([
        'geofence_radius_m' => 500,
    ]);

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.branch_id', $branch->id)
        ->assertJsonPath('data.company_id', $company->id)
        ->assertJsonPath('data.status', 'assigned');

    // Exactly one open history row should exist.
    expect(DeviceAssignmentHistory::query()
        ->where('device_id', $device->id)
        ->whereNull('unassigned_at')
        ->count())->toBe(1);
});

it('closes the prior history row when reassigning', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $device = Device::factory()->create();
    $companyA = Company::factory()->create();
    $branchA = Branch::factory()->for($companyA)->create();
    $companyB = Company::factory()->create();
    $branchB = Branch::factory()->for($companyB)->create();

    // First assignment
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $companyA->id,
        'branch_id' => $branchA->id,
    ])->assertOk();

    // Reassignment to a different company/branch
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $companyB->id,
        'branch_id' => $branchB->id,
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
    // Action layer (firstOrFail throws 404).
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $companyB->id,
        'branch_id' => $branchA->id,
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
    ])->assertForbidden();
});

// ============================ UNASSIGN =============================

it('unassigns a device and closes its open history row', function (): void {
    actingAsDeviceRole($this, PlatformRole::DeviceOperations->value);

    $device = Device::factory()->create();
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();

    // Assign first so there's something to unassign.
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
    ])->assertOk();

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/unassign", [
        'reason' => 'Branch closed',
    ])
        ->assertOk()
        ->assertJsonPath('data.branch_id', null)
        ->assertJsonPath('data.company_id', null)
        ->assertJsonPath('data.status', 'registered');

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
