<?php

declare(strict_types=1);

/**
 * Feature tests for the merchant portal-user admin endpoints
 * (blueprint §4.5). Flow rewritten from "invite by email" to
 * "create with password" — the admin enters name+email, server
 * generates a 20-char password, response carries the plaintext
 * ONCE, admin shares it out of band.
 *
 * Covers:
 *   - Create happy path: row persists with user_type=Merchant +
 *     status=Active + bcrypt-hashed password, plaintext password
 *     returned in the envelope, audit event written.
 *   - Gate: refuses create when no branches.
 *   - Gate: refuses create when no devices.
 *   - Cross-tenant 404 — a portal user from another merchant's
 *     /portal-users route returns 404.
 *   - Reset password: rotates the password, returns plaintext ONCE.
 *   - Suspend / reactivate flow.
 *   - Permission gate: Support can list, can't create.
 */

use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
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

/**
 * Helper — log in as a platform admin with the given role and
 * return the user so the test body can use it for assertions.
 */
function actingAsPortalAdmin(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

/**
 * Helper — build a "ready to create user" merchant: company +
 * branch + assigned device. Required by the blueprint §4.5 gate.
 */
function readyCompanyWithBranchAndDevice(): Company
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    Device::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
    ]);

    return $company;
}

// ============================ CREATE ===============================

it('creates a portal admin user with a generated password', function (): void {
    $admin = actingAsPortalAdmin($this, PlatformRole::OnboardingOfficer->value);
    $company = readyCompanyWithBranchAndDevice();

    $response = $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'Aisha Owner',
        'email' => 'aisha@example.test',
        'phone' => '+96891111111',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'aisha@example.test')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.user_type', 'merchant');

    // Plaintext password returned ONCE in the envelope.
    $plaintext = $response->json('plaintext_password');
    expect($plaintext)->toBeString()->and(strlen($plaintext))->toBe(20);

    // Hash matches what was stored on the user.
    $created = User::query()->where('email', 'aisha@example.test')->firstOrFail();
    expect(Hash::check($plaintext, $created->password))->toBeTrue();
    expect($created->user_type)->toBe(UserType::Merchant);
    expect($created->status)->toBe(UserStatus::Active);
    expect($created->company_id)->toBe($company->id);
    expect($created->invited_by_admin_id)->toBe($admin->id);
    // Initial user is unscoped — has access to every branch.
    expect($created->branch_scope_json)->toBeNull();
    // No setup token created (no email flow).
    expect($created->setup_token_hash)->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.created',
        'auditable_id' => $created->id,
    ]);
});

it('refuses to create when the merchant has no branches', function (): void {
    actingAsPortalAdmin($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();   // no branch, no device

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'NoBranch',
        'email' => 'a@example.test',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'Cannot create a portal user before the company has at least one branch.']);
});

it('refuses to create when the merchant has no devices', function (): void {
    actingAsPortalAdmin($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();
    Branch::factory()->for($company)->create();   // branch yes, device no

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'NoDevice',
        'email' => 'a@example.test',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'Cannot create a portal user before the company has at least one assigned device.']);
});

it('rejects duplicate emails across the platform', function (): void {
    actingAsPortalAdmin($this, PlatformRole::OnboardingOfficer->value);
    $company = readyCompanyWithBranchAndDevice();
    User::factory()->create(['email' => 'taken@example.test']);

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'Dup',
        'email' => 'taken@example.test',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('forbids Support role from creating a portal user', function (): void {
    actingAsPortalAdmin($this, PlatformRole::Support->value);
    $company = readyCompanyWithBranchAndDevice();

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'X',
        'email' => 'x@example.test',
    ])->assertForbidden();
});

// ============================ LIST + CROSS-TENANT ==================

it('lists portal users for the merchant', function (): void {
    actingAsPortalAdmin($this, PlatformRole::Support->value);
    $company = readyCompanyWithBranchAndDevice();

    User::factory()->count(2)->create([
        'company_id' => $company->id,
        'user_type' => UserType::Merchant,
    ]);
    // Decoy: a user in another company should NOT show.
    User::factory()->create([
        'company_id' => Company::factory()->create()->id,
        'user_type' => UserType::Merchant,
    ]);

    $response = $this->getJson("/admin/api/v1/merchants/{$company->uuid}/portal-users")
        ->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('returns 404 when fetching a portal user that belongs to a different merchant', function (): void {
    actingAsPortalAdmin($this, PlatformRole::OnboardingOfficer->value);
    $companyA = readyCompanyWithBranchAndDevice();
    $companyB = readyCompanyWithBranchAndDevice();
    $userOfB = User::factory()->create([
        'company_id' => $companyB->id,
        'user_type' => UserType::Merchant,
    ]);

    $this->patchJson("/admin/api/v1/merchants/{$companyA->uuid}/portal-users/{$userOfB->id}", [
        'status' => 'suspended',
    ])->assertNotFound();
});

// =========================== RESET PASSWORD ========================

it('resets a portal user password and returns the new plaintext once', function (): void {
    actingAsPortalAdmin($this, PlatformRole::OnboardingOfficer->value);
    $company = readyCompanyWithBranchAndDevice();

    // Seed an existing merchant user with a known password.
    $user = User::factory()->create([
        'company_id' => $company->id,
        'user_type' => UserType::Merchant,
        'status' => UserStatus::Active,
        'password' => 'initial-pass-12345',
    ]);
    $hashBefore = $user->fresh()->password;

    $response = $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users/{$user->id}/reset-password")
        ->assertOk();

    $plaintext = $response->json('plaintext_password');
    expect($plaintext)->toBeString()->and(strlen($plaintext))->toBe(20);

    $user->refresh();
    expect($user->password)->not->toBe($hashBefore);
    expect(Hash::check($plaintext, $user->password))->toBeTrue();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.password_reset',
        'auditable_id' => $user->id,
    ]);
});

it('refuses to reset password on a non-merchant user', function (): void {
    // A platform-admin id arriving via this scoped route would be a
    // routing mistake; the ensureSameTenant guard 404s before the
    // action runs because user_type=PlatformAdmin won't match the
    // merchant's company_id.
    actingAsPortalAdmin($this, PlatformRole::OnboardingOfficer->value);
    $company = readyCompanyWithBranchAndDevice();
    $platformUser = User::factory()->create([
        'user_type' => UserType::PlatformAdmin,
    ]);

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users/{$platformUser->id}/reset-password")
        ->assertNotFound();
});

// ============================ STATUS TOGGLE ========================

it('suspends and reactivates a portal user', function (): void {
    actingAsPortalAdmin($this, PlatformRole::OnboardingOfficer->value);
    $company = readyCompanyWithBranchAndDevice();
    $user = User::factory()->create([
        'company_id' => $company->id,
        'user_type' => UserType::Merchant,
        'status' => UserStatus::Active,
    ]);

    $this->patchJson("/admin/api/v1/merchants/{$company->uuid}/portal-users/{$user->id}", [
        'status' => 'suspended',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    $this->patchJson("/admin/api/v1/merchants/{$company->uuid}/portal-users/{$user->id}", [
        'status' => 'active',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});
