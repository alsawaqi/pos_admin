<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\Company;
use App\Models\MerchantCommissionProfile;
use App\Models\MerchantCommissionShare;
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

function actingAsCommissionRole(TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

it('returns a default 100%-merchant profile when none is configured', function (): void {
    actingAsCommissionRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $res = $this->getJson("/admin/api/v1/merchants/{$company->uuid}/commission-profile")
        ->assertOk()
        ->assertJsonPath('data.exists', false)
        ->assertJsonPath('data.shares', []);

    expect((float) $res->json('data.merchant_percent'))->toBe(100.0);
});

it('creates a commission profile and computes the merchant residual', function (): void {
    actingAsCommissionRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $res = $this->putJson("/admin/api/v1/merchants/{$company->uuid}/commission-profile", [
        'is_active' => true,
        'shares' => [
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.exists', true)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonCount(2, 'data.shares')
        ->assertJsonPath('data.shares.0.party_type', 'platform')
        ->assertJsonPath('data.shares.1.label', 'Acme Bank');

    expect((float) $res->json('data.total_share_percent'))->toBe(5.0);
    expect((float) $res->json('data.merchant_percent'))->toBe(95.0);

    $this->assertDatabaseHas('pos_commission_profiles', [
        'company_id' => $company->id,
        'is_active' => true,
        'merchant_percent' => 95.00,
    ]);
    $this->assertDatabaseHas('pos_commission_shares', ['party_type' => 'platform', 'percent' => 2.00]);
    $this->assertDatabaseHas('pos_commission_shares', ['party_type' => 'bank', 'percent' => 3.00]);
});

it('replaces the share lines wholesale on a second save', function (): void {
    actingAsCommissionRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/commission-profile", [
        'shares' => [
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ],
    ])->assertOk();

    // Re-save with a single, different line.
    $res = $this->putJson("/admin/api/v1/merchants/{$company->uuid}/commission-profile", [
        'shares' => [
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 10],
        ],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'data.shares');

    expect((float) $res->json('data.merchant_percent'))->toBe(90.0);

    // Exactly one profile + one share row survive (old lines deleted).
    expect(MerchantCommissionProfile::where('company_id', $company->id)->count())->toBe(1);
    expect(MerchantCommissionShare::count())->toBe(1);
});

it('rejects a profile whose parties exceed 100%', function (): void {
    actingAsCommissionRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/commission-profile", [
        'shares' => [
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 60],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 50],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['shares']);

    $this->assertDatabaseCount('pos_commission_profiles', 0);
});

it('rejects the merchant as a configurable share line', function (): void {
    actingAsCommissionRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/commission-profile", [
        'shares' => [
            ['party_type' => 'merchant', 'label' => 'Merchant', 'percent' => 10],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['shares.0.party_type']);
});

it('accepts an empty share list (merchant keeps 100%)', function (): void {
    actingAsCommissionRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $res = $this->putJson("/admin/api/v1/merchants/{$company->uuid}/commission-profile", [
        'shares' => [],
    ])
        ->assertOk()
        ->assertJsonCount(0, 'data.shares');

    expect((float) $res->json('data.merchant_percent'))->toBe(100.0);
});

it('forbids managing the profile without merchants.update', function (): void {
    actingAsCommissionRole($this, PlatformRole::FinanceViewer->value);
    $company = Company::factory()->create();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/commission-profile", [
        'shares' => [
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
        ],
    ])->assertForbidden();

    $this->assertDatabaseCount('pos_commission_profiles', 0);
});
