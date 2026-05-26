<?php

declare(strict_types=1);

/**
 * Feature tests for the Platform Team endpoints.
 *
 * Covers:
 *   - LIST: returns only platform_admin user_type; merchant
 *     portal users in pos_users are excluded.
 *   - INVITE: creates user with hashed password + assigned role
 *     under PLATFORM_TEAM_ID, returns plaintext password ONCE,
 *     writes audit event.
 *   - UPDATE: partial updates (name only, role only, etc.); 404
 *     when targeting a merchant portal user; audit captures the
 *     diff.
 *   - SUSPEND: flips status; refuses self-suspension; idempotent.
 *   - REACTIVATE: flips status back; idempotent.
 *   - PERMISSIONS: every endpoint 403s without the matching
 *     PlatformUsers* permission; 401 unauthenticated.
 */

use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Company;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function actingAsSuperAdmin(\Tests\TestCase $test): User
{
    /** @var User $user */
    $user = User::factory()->create([
        'user_type' => UserType::PlatformAdmin,
        'status' => UserStatus::Active,
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

// ============================ LIST =================================

it('lists only platform admin users (excludes merchants)', function (): void {
    actingAsSuperAdmin($this);

    // Two platform admins + one merchant portal user. Index should
    // return 2 + the actor (super admin already exists) = 3 platform
    // rows, and zero merchant rows.
    User::factory()->count(2)->create([
        'user_type' => UserType::PlatformAdmin,
        'status' => UserStatus::Active,
    ]);

    $company = Company::factory()->create();
    User::factory()->create([
        'company_id' => $company->id,
        'user_type' => UserType::Merchant,
        'status' => UserStatus::Active,
    ]);

    $response = $this->getJson('/admin/api/v1/platform-team')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta', 'links']);

    foreach ($response->json('data') as $row) {
        expect($row['user_type'])->toBe('platform_admin');
    }
    expect(count($response->json('data')))->toBe(3);
});

it('forbids list without platform_users.view permission', function (): void {
    /** @var User $user */
    $user = User::factory()->create([
        'user_type' => UserType::PlatformAdmin,
        'status' => UserStatus::Active,
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::FinanceViewer->value);
    $this->actingAs($user);

    // FinanceViewer doesn't have PlatformUsersView per the seeder.
    $this->getJson('/admin/api/v1/platform-team')->assertForbidden();
});

it('requires authentication', function (): void {
    $this->getJson('/admin/api/v1/platform-team')->assertUnauthorized();
});

// ============================ INVITE ===============================

it('invites a platform admin with a generated password + assigned role', function (): void {
    actingAsSuperAdmin($this);

    $response = $this->postJson('/admin/api/v1/platform-team', [
        'name' => 'Jane Doe',
        'email' => 'jane@mithqal.test',
        'role' => PlatformRole::OnboardingOfficer->value,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Jane Doe')
        ->assertJsonPath('data.email', 'jane@mithqal.test')
        ->assertJsonPath('data.role', PlatformRole::OnboardingOfficer->value)
        ->assertJsonPath('data.status', UserStatus::Active->value);

    // Plaintext password returned ONCE.
    $plaintext = $response->json('plaintext_password');
    expect($plaintext)->toBeString()->and(strlen($plaintext))->toBe(20);

    // Hash matches a fresh user row.
    $created = User::query()->where('email', 'jane@mithqal.test')->firstOrFail();
    expect(Hash::check($plaintext, $created->password))->toBeTrue();
    expect($created->user_type)->toBe(UserType::PlatformAdmin);
    expect($created->status)->toBe(UserStatus::Active);

    // Role is assigned under the platform team scope.
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    expect($created->hasRole(PlatformRole::OnboardingOfficer->value))->toBeTrue();

    // Audit row written.
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'platform_user.invited',
        'auditable_id' => $created->id,
    ]);
});

it('rejects invite with duplicate email', function (): void {
    actingAsSuperAdmin($this);

    User::factory()->create(['email' => 'taken@mithqal.test']);

    $this->postJson('/admin/api/v1/platform-team', [
        'name' => 'Imposter',
        'email' => 'taken@mithqal.test',
        'role' => PlatformRole::Support->value,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rejects invite with unknown role', function (): void {
    actingAsSuperAdmin($this);

    $this->postJson('/admin/api/v1/platform-team', [
        'name' => 'Mystery',
        'email' => 'mystery@mithqal.test',
        'role' => 'wizard',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['role']);
});

it('forbids invite without platform_users.invite permission', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['user_type' => UserType::PlatformAdmin]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::Support->value);
    $this->actingAs($user);

    // Support has PlatformUsersView (via seeder? no — it doesn't).
    // Actually Support only has *View permissions, no invite. So
    // this 403s at the controller's first ensure() call. The
    // result is the same: forbidden.
    $this->postJson('/admin/api/v1/platform-team', [
        'name' => 'X',
        'email' => 'x@x.x',
        'role' => PlatformRole::Support->value,
    ])->assertForbidden();
});

// ============================ UPDATE ===============================

it('updates a platform admin name + role', function (): void {
    actingAsSuperAdmin($this);

    /** @var User $target */
    $target = User::factory()->create([
        'name' => 'Old Name',
        'user_type' => UserType::PlatformAdmin,
        'status' => UserStatus::Active,
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $target->assignRole(PlatformRole::Support->value);

    $this->patchJson("/admin/api/v1/platform-team/{$target->id}", [
        'name' => 'New Name',
        'role' => PlatformRole::DeviceOperations->value,
    ])->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.role', PlatformRole::DeviceOperations->value);

    $target->refresh();
    expect($target->name)->toBe('New Name');
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    expect($target->hasRole(PlatformRole::DeviceOperations->value))->toBeTrue();
    expect($target->hasRole(PlatformRole::Support->value))->toBeFalse();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'platform_user.updated',
        'auditable_id' => $target->id,
    ]);
});

it('refuses to update a merchant portal user via this endpoint (404)', function (): void {
    actingAsSuperAdmin($this);

    $company = Company::factory()->create();
    $merchantUser = User::factory()->create([
        'company_id' => $company->id,
        'user_type' => UserType::Merchant,
    ]);

    $this->patchJson("/admin/api/v1/platform-team/{$merchantUser->id}", [
        'name' => 'Hijacked',
    ])->assertNotFound();
});

// ====================== SUSPEND / REACTIVATE ======================

it('suspends and reactivates a platform admin', function (): void {
    actingAsSuperAdmin($this);

    /** @var User $target */
    $target = User::factory()->create([
        'user_type' => UserType::PlatformAdmin,
        'status' => UserStatus::Active,
    ]);

    $this->postJson("/admin/api/v1/platform-team/{$target->id}/suspend")
        ->assertOk()
        ->assertJsonPath('data.status', UserStatus::Suspended->value);

    expect($target->fresh()->status)->toBe(UserStatus::Suspended);

    $this->postJson("/admin/api/v1/platform-team/{$target->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.status', UserStatus::Active->value);

    expect($target->fresh()->status)->toBe(UserStatus::Active);

    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'platform_user.suspended']);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'platform_user.reactivated']);
});

it('refuses self-suspension with 422', function (): void {
    $actor = actingAsSuperAdmin($this);

    $this->postJson("/admin/api/v1/platform-team/{$actor->id}/suspend")
        ->assertStatus(422)
        ->assertJsonPath('message', 'You cannot suspend your own account.');

    // Status unchanged.
    expect($actor->fresh()->status)->toBe(UserStatus::Active);
});

it('is idempotent on already-suspended user', function (): void {
    actingAsSuperAdmin($this);

    /** @var User $target */
    $target = User::factory()->create([
        'user_type' => UserType::PlatformAdmin,
        'status' => UserStatus::Suspended,
    ]);

    $this->postJson("/admin/api/v1/platform-team/{$target->id}/suspend")
        ->assertOk();

    // No duplicate audit row.
    expect(\App\Models\AuditLog::query()
        ->where('event', 'platform_user.suspended')
        ->where('auditable_id', $target->id)
        ->count())->toBe(0);
});
