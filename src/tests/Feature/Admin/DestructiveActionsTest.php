<?php

declare(strict_types=1);

/**
 * Feature tests for the destructive actions added in the
 * "edit/delete/permission audit" sweep:
 *
 *   DELETE /admin/api/v1/branches/{uuid}
 *   POST   /admin/api/v1/devices/{uuid}/decommission
 *   DELETE /admin/api/v1/merchants/{uuid}
 *
 * Covers:
 *   - Success path: row is soft-deleted, audit event written,
 *     downstream relations cleaned up where applicable.
 *   - Safety rail: 409 when destroying a parent with active
 *     children (branch with devices, merchant with branches/devices).
 *   - Permission gating: 403 when the role lacks the right key,
 *     401 unauthenticated.
 */

use App\Enums\BranchStatus;
use App\Enums\DeviceStatus;
use App\Enums\PlatformRole;
use App\Enums\UserType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceAssignmentHistory;
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

function actingAsSuperAdminForDestructive(\Tests\TestCase $test): User
{
    /** @var User $user */
    $user = User::factory()->create(['user_type' => UserType::PlatformAdmin]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

// ============================ BRANCH DELETE ===========================

it('soft-deletes an empty branch + writes branch.deleted audit', function (): void {
    actingAsSuperAdminForDestructive($this);

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();

    $this->deleteJson("/admin/api/v1/branches/{$branch->uuid}")
        ->assertNoContent();

    // Row is soft-deleted (deleted_at populated) but still in the DB
    // for audit-history lookup.
    expect(Branch::query()->withTrashed()->find($branch->id)->deleted_at)->not->toBeNull();
    expect(Branch::query()->find($branch->id))->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'branch.deleted',
        'auditable_id' => $branch->id,
    ]);
});

it('refuses to delete a branch that still has active devices (409)', function (): void {
    actingAsSuperAdminForDestructive($this);

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    Device::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => DeviceStatus::Assigned,
    ]);

    $response = $this->deleteJson("/admin/api/v1/branches/{$branch->uuid}")
        ->assertStatus(409);

    expect($response->json('message'))->toContain('1 active device');
    // Branch should still be present (not deleted).
    expect(Branch::query()->find($branch->id))->not->toBeNull();
});

it('forbids branch delete without branches.delete permission', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['user_type' => UserType::PlatformAdmin]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    // Onboarding has Branches{View,Create,Update,TransitionStatus}
    // but NOT BranchesDelete (only SuperAdmin via $all).
    $user->assignRole(PlatformRole::OnboardingOfficer->value);
    $this->actingAs($user);

    $branch = Branch::factory()->for(Company::factory())->create();

    $this->deleteJson("/admin/api/v1/branches/{$branch->uuid}")->assertForbidden();
});

// ========================= DEVICE DECOMMISSION =========================

it('decommissions a device — closes history row + flips status + soft-deletes', function (): void {
    actingAsSuperAdminForDestructive($this);

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $device = Device::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => DeviceStatus::Active,
    ]);
    // Open assignment history row — should be closed by decommission.
    $openHistory = DeviceAssignmentHistory::query()->create([
        'device_id' => $device->id,
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'assigned_at' => now()->subDay(),
        'assigned_by_admin_id' => null,
    ]);

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/decommission", [
        'reason' => 'End of life',
    ])->assertNoContent();

    // Status flipped + soft-deleted.
    $persisted = Device::query()->withTrashed()->find($device->id);
    expect($persisted->status)->toBe(DeviceStatus::Blocked);
    expect($persisted->deleted_at)->not->toBeNull();

    // History row closed with the right reason.
    $openHistory->refresh();
    expect($openHistory->unassigned_at)->not->toBeNull();
    expect($openHistory->unassign_reason)->toBe('End of life');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'device.decommissioned',
        'auditable_id' => $device->id,
    ]);
});

it('forbids decommission without devices.decommission permission', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['user_type' => UserType::PlatformAdmin]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    // Support has DevicesView but NOT DevicesDecommission.
    $user->assignRole(PlatformRole::Support->value);
    $this->actingAs($user);

    $device = Device::factory()->create();

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/decommission")
        ->assertForbidden();
});

// ========================= MERCHANT DELETE =============================

it('soft-deletes an empty merchant + writes company.deleted audit', function (): void {
    actingAsSuperAdminForDestructive($this);

    $company = Company::factory()->create();

    $this->deleteJson("/admin/api/v1/merchants/{$company->uuid}")
        ->assertNoContent();

    expect(Company::query()->withTrashed()->find($company->id)->deleted_at)->not->toBeNull();
    expect(Company::query()->find($company->id))->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'company.deleted',
        'auditable_id' => $company->id,
    ]);
});

it('refuses to delete a merchant with active branches (409)', function (): void {
    actingAsSuperAdminForDestructive($this);

    $company = Company::factory()->create();
    Branch::factory()->for($company)->create();

    $response = $this->deleteJson("/admin/api/v1/merchants/{$company->uuid}")
        ->assertStatus(409);

    expect($response->json('message'))->toContain('1 active branch');
});

it('refuses to delete a merchant with active devices (409)', function (): void {
    actingAsSuperAdminForDestructive($this);

    $company = Company::factory()->create();
    Device::factory()->create([
        'company_id' => $company->id,
        'branch_id' => null,
    ]);

    $response = $this->deleteJson("/admin/api/v1/merchants/{$company->uuid}")
        ->assertStatus(409);

    expect($response->json('message'))->toContain('active device');
});

it('forbids merchant delete without merchants.delete permission', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['user_type' => UserType::PlatformAdmin]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    // Onboarding can create + update merchants but NOT delete.
    $user->assignRole(PlatformRole::OnboardingOfficer->value);
    $this->actingAs($user);

    $company = Company::factory()->create();

    $this->deleteJson("/admin/api/v1/merchants/{$company->uuid}")->assertForbidden();
});

// =========================== AUTH GATES ===============================

it('requires authentication on every destructive endpoint', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $device = Device::factory()->create();

    $this->deleteJson("/admin/api/v1/branches/{$branch->uuid}")->assertUnauthorized();
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/decommission")->assertUnauthorized();
    $this->deleteJson("/admin/api/v1/merchants/{$company->uuid}")->assertUnauthorized();
});
