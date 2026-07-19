<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\Company;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function actingAsAudienceRole(TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

it('defaults audience measurement to OFF', function (): void {
    actingAsAudienceRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}/audience-measurement")
        ->assertOk()
        ->assertJsonPath('data.enabled', false);
});

it('turns the consent on and serves it back', function (): void {
    actingAsAudienceRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/audience-measurement", ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.enabled', true);

    expect(json_decode((string) DB::table('pos_company_settings')
        ->where('company_id', $company->id)
        ->where('key', 'audience_measurement')
        ->value('value'), true))->toBeTrue();

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}/audience-measurement")
        ->assertOk()
        ->assertJsonPath('data.enabled', true);
});

it('revokes the consent in place (single settings row)', function (): void {
    actingAsAudienceRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/audience-measurement", ['enabled' => true])->assertOk();
    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/audience-measurement", ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    expect(DB::table('pos_company_settings')
        ->where('company_id', $company->id)
        ->where('key', 'audience_measurement')
        ->count())->toBe(1);

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}/audience-measurement")
        ->assertOk()
        ->assertJsonPath('data.enabled', false);
});

it('validates the payload', function (): void {
    actingAsAudienceRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/audience-measurement", [])
        ->assertStatus(422);
});

it('forbids a role without merchant update rights', function (): void {
    actingAsAudienceRole($this, PlatformRole::Support->value);
    $company = Company::factory()->create();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/audience-measurement", ['enabled' => true])
        ->assertForbidden();
});
