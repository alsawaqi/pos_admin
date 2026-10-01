<?php

declare(strict_types=1);

/**
 * Feature tests for the merchant portal-user admin endpoints
 * (blueprint §4.5). LAUNCH-P1 P1-2: the admin enters name+email and
 * the merchant receives a single-use set-password link (no password is
 * generated or shown). Deeper link tests live in
 * tests/Feature/LaunchP1/MerchantSetPasswordLinkTest.php.
 *
 * Covers:
 *   - Create happy path: row persists with user_type=Merchant +
 *     status=Active + NO password, set-password link returned, audit.
 *   - P1-14: the first login needs no branch and no device.
 *   - Cross-tenant 404 — a portal user from another merchant's
 *     /portal-users route returns 404.
 *   - Reset password: a reset link, never a plaintext password.
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
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

/**
 * Helper — log in as a platform admin with the given role and
 * return the user so the test body can use it for assertions.
 */
function actingAsPortalAdmin(TestCase $test, string $role): User
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

it('creates a portal admin user with a set-password link', function (): void {
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

    // LAUNCH-P1 P1-2: no password exists; a one-time set-password link
    // is returned (and emailed when mail is configured).
    $response->assertJsonMissingPath('plaintext_password')
        ->assertJsonPath('set_password_link.purpose', 'invite');
    expect((string) $response->json('set_password_link.url'))->toContain('/setup-password?token=');

    $created = User::query()->where('email', 'aisha@example.test')->firstOrFail();
    expect($created->password)->toBeNull();
    expect($created->user_type)->toBe(UserType::Merchant);
    expect($created->status)->toBe(UserStatus::Active);
    expect($created->company_id)->toBe($company->id);
    expect($created->invited_by_admin_id)->toBe($admin->id);
    // Initial user is unscoped — has access to every branch.
    expect($created->branch_scope_json)->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.created',
        'auditable_id' => $created->id,
    ]);
});

it('creates the first login before the merchant has a branch or a device', function (): void {
    // LAUNCH-P1 P1-14: neither is a technical dependency of the portal
    // (the first user is unscoped), and the owner gives the login
    // before branches and devices exist.
    actingAsPortalAdmin($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();   // no branch, no device

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'NoBranch',
        'email' => 'a@example.test',
    ])->assertCreated();

    $company2 = Company::factory()->create();
    Branch::factory()->for($company2)->create();   // branch yes, device no

    $this->postJson("/admin/api/v1/merchants/{$company2->uuid}/portal-users", [
        'name' => 'NoDevice',
        'email' => 'b@example.test',
    ])->assertCreated();
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

it('lists portal users even when a row holds undecryptable phone ciphertext', function (): void {
    actingAsPortalAdmin($this, PlatformRole::Support->value);
    $company = readyCompanyWithBranchAndDevice();

    $user = User::factory()->create([
        'company_id' => $company->id,
        'user_type' => UserType::Merchant,
    ]);
    // Simulate APP_KEY drift: overwrite phone with ciphertext produced under
    // a DIFFERENT key — what a diverged sibling portal writes into the shared
    // pos_users table. The tab must render (phone null), not 500 on
    // DecryptException ("The MAC is invalid").
    $foreign = new Encrypter(random_bytes(32), config('app.cipher'));
    DB::table('pos_users')
        ->where('id', $user->id)
        ->update(['phone' => $foreign->encrypt('99887766', false)]);

    $response = $this->getJson("/admin/api/v1/merchants/{$company->uuid}/portal-users")
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $user->id);
    expect($row)->not->toBeNull()
        ->and($row['phone'])->toBeNull();
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

it('sends a portal user a reset link instead of showing a new password', function (): void {
    actingAsPortalAdmin($this, PlatformRole::OnboardingOfficer->value);
    $company = readyCompanyWithBranchAndDevice();

    // Seed an existing merchant user with a known password.
    $user = User::factory()->create([
        'company_id' => $company->id,
        'user_type' => UserType::Merchant,
        'status' => UserStatus::Active,
        'password' => 'initial-pass-12345',
    ]);

    $response = $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users/{$user->id}/reset-password")
        ->assertOk();

    // LAUNCH-P1 P1-2: no plaintext password; a 60-minute reset link.
    $response->assertJsonMissingPath('plaintext_password')
        ->assertJsonPath('set_password_link.purpose', 'reset');
    expect((string) $response->json('set_password_link.url'))->toContain('/reset-password?token=');

    // Owner follow-up: the old password is blocked at once; only the
    // link can set a new one.
    $user->refresh();
    expect($user->password)->toBeNull();

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
