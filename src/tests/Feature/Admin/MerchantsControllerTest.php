<?php

declare(strict_types=1);

use App\Enums\DocumentType;
use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\BusinessActivity;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyOwner;
use App\Models\Device;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

/**
 * LAUNCH-P1 P1-18: a merchant needs one business activity marked primary.
 *
 * @return list<array{business_activity_id: int, is_primary: bool}>
 */
function primaryActivityPayload(): array
{
    return [['business_activity_id' => BusinessActivity::factory()->create()->id, 'is_primary' => true]];
}

function actingAsRole(TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

it('lists merchants for users with merchants.view permission', function (): void {
    actingAsRole($this, PlatformRole::SuperAdmin->value);
    Company::factory()->count(3)->create();

    $this->getJson('/admin/api/v1/merchants')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta', 'links']);
});

it('refuses to list merchants for users without permission', function (): void {
    actingAsRole($this, PlatformRole::FinanceViewer->value);
    Company::factory()->count(2)->create();

    $this->getJson('/admin/api/v1/merchants')->assertOk();

    actingAsRole($this, PlatformRole::Support->value);
    $this->getJson('/admin/api/v1/merchants')->assertOk();
});

it('forbids unauthenticated requests', function (): void {
    $this->getJson('/admin/api/v1/merchants')->assertUnauthorized();
});

it('creates a merchant via the JSON API with full Oman compliance fields', function (): void {
    actingAsRole($this, PlatformRole::OnboardingOfficer->value);

    $response = $this->postJson('/admin/api/v1/merchants', [
        'name' => 'Test Cafe',
        'name_ar' => 'مقهى الاختبار',
        'compliance' => [
            'cr_number' => '7777777',
            'cr_issue_date' => '2025-01-10',
            'cr_expiry_date' => '2028-01-09',
            'vat_number' => 'OM550012345',
        ],
        'contact' => ['name' => 'Ahmed', 'email' => 'cafe@example.test'],
        // owners[] — at least one, exactly one with is_primary=true.
        'owners' => [
            ['full_name_en' => 'Ahmed Al-Said', 'nationality' => 'OM', 'is_primary' => true],
        ],
        'activities' => primaryActivityPayload(),
        'default_currency' => 'OMR',
        'default_locale' => 'en',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Test Cafe')
        ->assertJsonPath('data.compliance.cr_number', '7777777')
        ->assertJsonPath('data.owners.0.full_name_en', 'Ahmed Al-Said')
        ->assertJsonPath('data.owners.0.is_primary', true);

    $this->assertDatabaseHas(Company::class, ['cr_number' => '7777777']);
    $this->assertDatabaseHas('pos_company_owners', [
        'full_name_en' => 'Ahmed Al-Said',
        'is_primary' => true,
    ]);
});

it('persists multiple owners and marks exactly one as primary', function (): void {
    actingAsRole($this, PlatformRole::OnboardingOfficer->value);

    $this->postJson('/admin/api/v1/merchants', [
        'name' => 'Partnership Co',
        'compliance' => ['cr_number' => '8888888'],
        'contact' => ['name' => 'Partner Contact'],
        'activities' => primaryActivityPayload(),
        'owners' => [
            ['full_name_en' => 'Partner One', 'is_primary' => true, 'ownership_percentage' => 60],
            ['full_name_en' => 'Partner Two', 'is_primary' => false, 'ownership_percentage' => 40],
        ],
    ])->assertStatus(201)
        ->assertJsonCount(2, 'data.owners');

    expect(CompanyOwner::query()->where('full_name_en', 'Partner One')->value('is_primary'))->toBe(true);
    expect(CompanyOwner::query()->where('full_name_en', 'Partner Two')->value('is_primary'))->toBe(false);
});

it('rejects merchant create when no owner is marked primary', function (): void {
    actingAsRole($this, PlatformRole::OnboardingOfficer->value);

    $this->postJson('/admin/api/v1/merchants', [
        'name' => 'NoPrimary Co',
        'compliance' => ['cr_number' => '6666666'],
        'contact' => [],
        'owners' => [
            ['full_name_en' => 'Person A', 'is_primary' => false],
            ['full_name_en' => 'Person B', 'is_primary' => false],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['owners']);
});

it('rejects duplicate CR numbers on creation', function (): void {
    actingAsRole($this, PlatformRole::OnboardingOfficer->value);
    Company::factory()->create(['cr_number' => '5555555']);

    $this->postJson('/admin/api/v1/merchants', [
        'name' => 'Duplicate Co',
        'compliance' => ['cr_number' => '5555555'],
        'contact' => [],
        'owners' => [['full_name_en' => 'X', 'is_primary' => true]],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['compliance.cr_number']);
});

it('transitions a merchant status through the API with audit + history', function (): void {
    $user = actingAsRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();
    // LAUNCH-P1 P1-19: Active needs a verified CR + owner ID card.
    CompanyDocument::factory()->for($company)->verified()
        ->create(['document_type' => DocumentType::CrCertificate]);
    CompanyDocument::factory()->for($company)->verified()
        ->create(['document_type' => DocumentType::OwnerIdCard]);

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/status", [
        'target_status' => 'active',
    ])->assertOk()
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('pos_company_status_history', [
        'company_id' => $company->id,
        'to_status' => 'active',
        'changed_by_user_id' => $user->id,
    ]);
});

// =================== branches_count + devices_count =====================
// The merchant page shows these counts. (Since LAUNCH-P1 P1-14 they no
// longer gate the first portal login.) See MerchantsController::show.

it('show endpoint returns branches_count + devices_count', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $this->actingAs($user);

    $company = Company::factory()->create();
    Branch::factory()->for($company)->count(2)->create();
    $branch = $company->branches()->first();
    Device::factory()->for($company)->create([
        'branch_id' => $branch->id,
    ]);

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}")
        ->assertOk()
        ->assertJsonPath('data.branches_count', 2)
        ->assertJsonPath('data.devices_count', 1);
});

it('store endpoint returns branches_count=0 + devices_count=0 on a fresh merchant', function (): void {
    // After creating a merchant the counts should be present but zero.
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $this->actingAs($user);

    $this->postJson('/admin/api/v1/merchants', [
        'name' => 'Fresh Co',
        'compliance' => [
            'cr_number' => (string) random_int(1_000_000, 9_999_999),
        ],
        'contact' => [
            'name' => 'Test Contact',
            'phone' => '+96812345678',
            'email' => 'contact@fresh.test',
        ],
        'activities' => primaryActivityPayload(),
        'owners' => [[
            'full_name_en' => 'A Founder',
            'is_primary' => true,
        ]],
    ])
        ->assertCreated()
        ->assertJsonPath('data.branches_count', 0)
        ->assertJsonPath('data.devices_count', 0);
});
