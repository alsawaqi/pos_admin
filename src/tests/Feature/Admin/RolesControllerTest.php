<?php

declare(strict_types=1);

/**
 * Feature tests for the platform-side Roles & Permissions
 * builder (App\Http\Controllers\Api\Admin\RolesController).
 *
 * What this suite proves end-to-end through the HTTP stack:
 *
 *   - actingAs() + spatie team_id=PLATFORM_TEAM_ID combination
 *     gives the controller's $user->can(...) checks a valid
 *     scope to read against.
 *   - System roles (the 5 defaults seeded by PlatformRoleSeeder)
 *     can have their permission set edited but cannot be
 *     renamed or deleted.
 *   - Custom roles are fully CRUD-able by anyone with
 *     RolesManage; visibility is open to anyone with RolesView.
 *   - Delete refused when any user still holds the role (422
 *     with a clear count).
 *   - SuperAdmin self-rescue: certain permissions can never be
 *     stripped from the SuperAdmin role.
 *   - Cross-team safety: passing a merchant role id (team_id =
 *     a company id, not 0) returns 404 — those rows are
 *     invisible to the admin role endpoints.
 *   - Validation: name uniqueness within platform team,
 *     permissions must be known PlatformPermission values.
 */

use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Company;
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
 * Build a platform admin user with the requested role and
 * actingAs them. Mirrors the helper in PlatformTeamControllerTest
 * but takes the role as a parameter so we can vary the actor
 * across permission-gate tests.
 */
function actingAsPlatformWithRole(\Tests\TestCase $test, string $roleName): User
{
    /** @var User $user */
    $user = User::factory()->create([
        'user_type' => UserType::PlatformAdmin,
        'status' => UserStatus::Active,
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($roleName);
    $test->actingAs($user);

    return $user;
}

// =================== LIST ===================

it('lists every role in the platform team scope', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    $response = $this->getJson('/admin/api/v1/roles')->assertOk();

    // The 5 seeded defaults must all be present.
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain(
        PlatformRole::SuperAdmin->value,
        PlatformRole::OnboardingOfficer->value,
        PlatformRole::DeviceOperations->value,
        PlatformRole::Support->value,
        PlatformRole::FinanceViewer->value,
    );

    // Each row carries the new Phase 4.8 fields.
    foreach ($response->json('data') as $row) {
        expect($row)->toHaveKeys(['id', 'name', 'description', 'is_system', 'permissions', 'user_count']);
        expect($row['is_system'])->toBeTrue(); // all seeded roles
    }
});

it('does not surface merchant-team roles in the platform list', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    // Seed a merchant company's role into the same table but
    // under a different team_id — must NOT appear in the
    // platform-scoped listing.
    $company = Company::factory()->create();
    Role::query()->create([
        'name' => 'merchant_decoy_role',
        'guard_name' => 'web',
        'team_id' => $company->id,
    ]);

    $response = $this->getJson('/admin/api/v1/roles')->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->not->toContain('merchant_decoy_role');
});

// =================== CATALOG ===================

it('returns the grouped permission catalog with EN+AR labels', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    $response = $this->getJson('/admin/api/v1/roles/catalog')->assertOk();
    $groups = $response->json('data');

    // Top-level grouping has the categories we expect.
    $groupKeys = collect($groups)->pluck('key')->all();
    expect($groupKeys)->toContain('merchants', 'branches', 'devices', 'roles', 'audit_logs');

    // Every permission carries both labels.
    foreach ($groups as $group) {
        foreach ($group['permissions'] as $perm) {
            expect($perm)->toHaveKeys(['key', 'label_en', 'label_ar']);
            expect($perm['label_en'])->toBeString()->not->toBeEmpty();
            expect($perm['label_ar'])->toBeString()->not->toBeEmpty();
        }
    }
});

// =================== CREATE ===================

it('creates a custom role with the requested permissions', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    $response = $this->postJson('/admin/api/v1/roles', [
        'name' => 'Read-Only Auditor',
        'description' => 'Custom role for external auditors — read-only across everything.',
        'permissions' => [
            PlatformPermission::MerchantsView->value,
            PlatformPermission::AuditLogsView->value,
            PlatformPermission::ReportsView->value,
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Read-Only Auditor')
        ->assertJsonPath('data.is_system', false);

    $role = Role::query()
        ->where('name', 'Read-Only Auditor')
        ->where('team_id', TenantContext::PLATFORM_TEAM_ID)
        ->firstOrFail();
    // Cast explicitly — spatie's Role model has no bool cast,
    // so sqlite returns is_system as int 0/1. The user-facing
    // JSON cast happens in RoleResource (verified by the
    // is_system: false assertion on the response above).
    expect((bool) $role->is_system)->toBeFalse();
    expect($role->permissions()->pluck('name')->sort()->values()->all())->toBe([
        PlatformPermission::AuditLogsView->value,
        PlatformPermission::MerchantsView->value,
        PlatformPermission::ReportsView->value,
    ]);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'role.created',
        'auditable_id' => $role->id,
    ]);
});

it('silently drops unknown permission strings on create', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    // Mixing one real perm + one bogus perm — validator should
    // reject the bogus one with 422 (Rule::in catches it).
    $this->postJson('/admin/api/v1/roles', [
        'name' => 'Bogus Test',
        'permissions' => [
            PlatformPermission::MerchantsView->value,
            'something.completely.fake',
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['permissions.1']);
});

it('rejects a duplicate role name within the platform team', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    $this->postJson('/admin/api/v1/roles', [
        'name' => PlatformRole::SuperAdmin->value, // collides with seeded default
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

// =================== UPDATE ===================

it('edits a custom role name, description, and permissions', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    // Seed a custom role to mutate.
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    /** @var Role $role */
    $role = Role::query()->create([
        'name' => 'Original',
        'guard_name' => 'web',
        'team_id' => TenantContext::PLATFORM_TEAM_ID,
        'is_system' => false,
        'description' => 'old',
    ]);

    $this->patchJson("/admin/api/v1/roles/{$role->id}", [
        'name' => 'Renamed',
        'description' => 'new',
        'permissions' => [PlatformPermission::MerchantsView->value],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.description', 'new');

    $role->refresh();
    expect($role->name)->toBe('Renamed');
    expect($role->permissions()->pluck('name')->all())->toBe([PlatformPermission::MerchantsView->value]);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'role.updated',
        'auditable_id' => $role->id,
    ]);
});

it('refuses to rename a system role but allows permission edits', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $supportRole = Role::query()
        ->where('name', PlatformRole::Support->value)
        ->where('team_id', TenantContext::PLATFORM_TEAM_ID)
        ->firstOrFail();

    // Rename attempt → 422 with a clear message.
    $this->patchJson("/admin/api/v1/roles/{$supportRole->id}", [
        'name' => 'Renamed Support',
    ])->assertStatus(422);

    // Permission edit on the same row → succeeds.
    $this->patchJson("/admin/api/v1/roles/{$supportRole->id}", [
        'description' => 'Updated description for Support',
        'permissions' => [PlatformPermission::AuditLogsView->value],
    ])
        ->assertOk()
        ->assertJsonPath('data.description', 'Updated description for Support');
});

it('refuses to strip a locked permission from SuperAdmin', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $superAdminRole = Role::query()
        ->where('name', PlatformRole::SuperAdmin->value)
        ->where('team_id', TenantContext::PLATFORM_TEAM_ID)
        ->firstOrFail();

    $response = $this->patchJson("/admin/api/v1/roles/{$superAdminRole->id}", [
        // Submit the full enum MINUS roles.manage — the locked
        // self-rescue permission. Action layer must refuse.
        'permissions' => array_values(array_diff(
            PlatformPermission::values(),
            ['roles.manage'],
        )),
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('roles.manage');
});

// =================== DELETE ===================

it('deletes a custom role with zero assigned users', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $role = Role::query()->create([
        'name' => 'To Be Deleted',
        'guard_name' => 'web',
        'team_id' => TenantContext::PLATFORM_TEAM_ID,
        'is_system' => false,
    ]);
    $roleId = $role->id;

    $this->deleteJson("/admin/api/v1/roles/{$role->id}")->assertNoContent();

    expect(Role::query()->find($roleId))->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'role.deleted',
        'auditable_id' => $roleId,
    ]);
});

it('refuses to delete a system role', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $superAdminRole = Role::query()
        ->where('name', PlatformRole::SuperAdmin->value)
        ->where('team_id', TenantContext::PLATFORM_TEAM_ID)
        ->firstOrFail();

    $this->deleteJson("/admin/api/v1/roles/{$superAdminRole->id}")
        ->assertStatus(422);

    // Still there.
    expect(Role::query()->find($superAdminRole->id))->not->toBeNull();
});

it('refuses to delete a custom role still assigned to a user', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $role = Role::query()->create([
        'name' => 'In Use',
        'guard_name' => 'web',
        'team_id' => TenantContext::PLATFORM_TEAM_ID,
        'is_system' => false,
    ]);

    $assignee = User::factory()->create([
        'user_type' => UserType::PlatformAdmin,
        'status' => UserStatus::Active,
    ]);
    $assignee->assignRole($role);

    $response = $this->deleteJson("/admin/api/v1/roles/{$role->id}")
        ->assertStatus(422);
    expect($response->json('message'))->toContain('assigned to');

    expect(Role::query()->find($role->id))->not->toBeNull();
});

// =================== CROSS-TEAM ===================

it('returns 404 when targeting a role from a different team scope', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    // A merchant role under team_id = a company id.
    $company = Company::factory()->create();
    $merchantRole = Role::query()->create([
        'name' => 'merchant_owner',
        'guard_name' => 'web',
        'team_id' => $company->id,
        'is_system' => false,
    ]);

    $this->patchJson("/admin/api/v1/roles/{$merchantRole->id}", ['description' => 'never'])
        ->assertNotFound();
    $this->deleteJson("/admin/api/v1/roles/{$merchantRole->id}")
        ->assertNotFound();
});

// =================== PERMISSION GATES ===================

it('forbids reading the role list without RolesView', function (): void {
    // Build a custom role with NO permissions, assign to a fresh
    // user, then probe the list endpoint.
    /** @var User $user */
    $user = User::factory()->create([
        'user_type' => UserType::PlatformAdmin,
        'status' => UserStatus::Active,
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $emptyRole = Role::query()->create([
        'name' => 'empty',
        'guard_name' => 'web',
        'team_id' => TenantContext::PLATFORM_TEAM_ID,
        'is_system' => false,
    ]);
    $user->assignRole($emptyRole);
    $this->actingAs($user);

    $this->getJson('/admin/api/v1/roles')->assertForbidden();
});

it('forbids creating a role without RolesManage', function (): void {
    // FinanceViewer holds RolesView (so they can browse the
    // catalog for context) but NOT RolesManage.
    actingAsPlatformWithRole($this, PlatformRole::FinanceViewer->value);

    $this->postJson('/admin/api/v1/roles', [
        'name' => 'Sneaky',
    ])->assertForbidden();
});

// =================== ASSIGN ROLES TO USER ===================

it('assigns multiple roles to a platform user', function (): void {
    $admin = actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    /** @var User $target */
    $target = User::factory()->create([
        'user_type' => UserType::PlatformAdmin,
        'status' => UserStatus::Active,
    ]);

    $this->patchJson("/admin/api/v1/platform-team/{$target->id}/roles", [
        'roles' => [
            PlatformRole::OnboardingOfficer->value,
            PlatformRole::Support->value,
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $target->id)
        ->assertJsonCount(2, 'data.roles');

    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    expect($target->fresh()->getRoleNames()->sort()->values()->all())->toBe([
        PlatformRole::OnboardingOfficer->value,
        PlatformRole::Support->value,
    ]);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'platform_user.roles_changed',
        'auditable_id' => $target->id,
    ]);
});

it('refuses an actor removing their own SuperAdmin role', function (): void {
    $admin = actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    $response = $this->patchJson("/admin/api/v1/platform-team/{$admin->id}/roles", [
        'roles' => [PlatformRole::Support->value],
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('Super Admin');
});

it('returns 404 when assigning platform roles to a merchant user', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::SuperAdmin->value);

    $company = Company::factory()->create();
    $merchantUser = User::factory()->create([
        'company_id' => $company->id,
        'user_type' => UserType::Merchant,
        'status' => UserStatus::Active,
    ]);

    $this->patchJson("/admin/api/v1/platform-team/{$merchantUser->id}/roles", [
        'roles' => [PlatformRole::Support->value],
    ])->assertNotFound();
});

it('forbids assigning roles without PlatformUsersUpdateRoles', function (): void {
    actingAsPlatformWithRole($this, PlatformRole::Support->value);

    $target = User::factory()->create([
        'user_type' => UserType::PlatformAdmin,
        'status' => UserStatus::Active,
    ]);

    $this->patchJson("/admin/api/v1/platform-team/{$target->id}/roles", [
        'roles' => [PlatformRole::Support->value],
    ])->assertForbidden();
});
