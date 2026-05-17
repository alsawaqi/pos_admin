<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function actingAsRole(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
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
        'owner' => ['full_name_en' => 'Ahmed Al-Said', 'nationality' => 'OM'],
        'default_currency' => 'OMR',
        'default_locale' => 'en',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Test Cafe')
        ->assertJsonPath('data.compliance.cr_number', '7777777');

    $this->assertDatabaseHas(Company::class, ['cr_number' => '7777777']);
});

it('rejects duplicate CR numbers on creation', function (): void {
    actingAsRole($this, PlatformRole::OnboardingOfficer->value);
    Company::factory()->create(['cr_number' => '5555555']);

    $this->postJson('/admin/api/v1/merchants', [
        'name' => 'Duplicate Co',
        'compliance' => ['cr_number' => '5555555'],
        'contact' => [],
        'owner' => ['full_name_en' => 'X'],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['compliance.cr_number']);
});

it('transitions a merchant status through the API with audit + history', function (): void {
    $user = actingAsRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/status", [
        'target_status' => 'active',
    ])->assertOk()
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('pos_admin_company_status_history', [
        'company_id' => $company->id,
        'to_status' => 'active',
        'changed_by_user_id' => $user->id,
    ]);
});
