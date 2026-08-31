<?php

declare(strict_types=1);

use App\Enums\PlatformPermission;
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

function actingAsDineInRoundModeRole(TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

/**
 * @param  list<string>  $permissions
 */
function actingAsDineInRoundModePermissions(TestCase $test, array $permissions): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

    /** @var User $user */
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    $test->actingAs($user);

    return $user;
}

it('defaults missing or invalid dine-in round modes to kitchen direct', function (): void {
    actingAsDineInRoundModeRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode")
        ->assertOk()
        ->assertJsonPath('data.mode', 'kitchen_direct');

    DB::table('pos_company_settings')->insert([
        'company_id' => $company->id,
        'key' => 'dine_in_round_mode',
        'value' => json_encode('unsupported'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode")
        ->assertOk()
        ->assertJsonPath('data.mode', 'kitchen_direct');
});

it('stores each valid mode and updates the single company setting row in place', function (): void {
    actingAsDineInRoundModeRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode", [
        'mode' => 'staff_confirm',
    ])->assertOk()
        ->assertJsonPath('data.mode', 'staff_confirm');

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode", [
        'mode' => 'kitchen_direct',
    ])->assertOk()
        ->assertJsonPath('data.mode', 'kitchen_direct');

    $row = DB::table('pos_company_settings')
        ->where('company_id', $company->id)
        ->where('key', 'dine_in_round_mode');

    expect($row->count())->toBe(1)
        ->and(json_decode((string) $row->value('value'), true))->toBe('kitchen_direct');
});

it('validates the dine-in round mode allow-list', function (): void {
    actingAsDineInRoundModeRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mode');

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode", [
        'mode' => 'automatic',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('mode');
});

it('uses CompanyPolicy view and update abilities', function (): void {
    actingAsDineInRoundModeRole($this, PlatformRole::Support->value);
    $company = Company::factory()->create();

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode")
        ->assertOk();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode", [
        'mode' => 'staff_confirm',
    ])->assertForbidden();
});

it('lets a merchant policy editor without device visibility read and edit both policies', function (): void {
    $user = actingAsDineInRoundModePermissions($this, [
        PlatformPermission::MerchantsView->value,
        PlatformPermission::MerchantsUpdate->value,
    ]);
    $company = Company::factory()->create();

    expect($user->can(PlatformPermission::DevicesView->value))->toBeFalse();

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}/audience-measurement")
        ->assertOk();
    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/audience-measurement", [
        'enabled' => true,
    ])->assertOk()
        ->assertJsonPath('data.enabled', true);

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode")
        ->assertOk();
    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode", [
        'mode' => 'staff_confirm',
    ])->assertOk()
        ->assertJsonPath('data.mode', 'staff_confirm');
});
