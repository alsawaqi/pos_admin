<?php

declare(strict_types=1);

use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use App\Models\Branch;
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

it('returns method not allowed for every role and leaves the company setting unchanged', function (): void {
    $company = Company::factory()->create();

    DB::table('pos_company_settings')->insert([
        'company_id' => $company->id,
        'key' => 'dine_in_round_mode',
        'value' => '"staff_confirm"',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);
    $before = (array) DB::table('pos_company_settings')->sole();

    foreach (PlatformRole::cases() as $role) {
        actingAsDineInRoundModeRole($this, $role->value);
        $this->putJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode", [
            'mode' => 'kitchen_direct',
        ])->assertMethodNotAllowed();

        expect((array) DB::table('pos_company_settings')->sole())->toBe($before);
    }
});

it('keeps CompanyPolicy view access while the write route is absent for Support', function (): void {
    actingAsDineInRoundModeRole($this, PlatformRole::Support->value);
    $company = Company::factory()->create();

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode")
        ->assertOk();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode", [
        'mode' => 'staff_confirm',
    ])->assertMethodNotAllowed();
});

it('lets a merchant policy editor without device visibility read the round mode and edit audience measurement', function (): void {
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
});

it('lists only valid active branch overrides for the requested merchant in name order', function (): void {
    actingAsDineInRoundModeRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    DB::table('pos_company_settings')->insert([
        'company_id' => $company->id,
        'key' => 'dine_in_round_mode',
        'value' => '"staff_confirm"',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $insertOverride = static function (Branch $branch, string $raw, ?int $companyId = null, string $key = 'dine_in_round_mode'): void {
        DB::table('pos_branch_settings')->insert([
            'company_id' => $companyId ?? $branch->company_id,
            'branch_id' => $branch->id,
            'key' => $key,
            'value' => $raw,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };
    $zulu = Branch::factory()->for($company)->create([
        'uuid' => '10000000-0000-4000-8000-000000000002',
        'name' => 'Zulu',
    ]);
    $alpha = Branch::factory()->for($company)->create([
        'uuid' => '10000000-0000-4000-8000-000000000001',
        'name' => 'Alpha',
    ]);
    $insertOverride($zulu, '"staff_confirm"');
    $insertOverride($alpha, '"kitchen_direct"');
    Branch::factory()->for($company)->create(['name' => 'Inherited']);

    foreach (['{not-json', '["staff_confirm"]', '"unknown-mode"', 'null'] as $index => $raw) {
        $insertOverride(Branch::factory()->for($company)->create(['name' => 'Garbage '.$index]), $raw);
    }
    $retired = Branch::factory()->for($company)->create(['name' => 'Retired']);
    $insertOverride($retired, '"staff_confirm"');
    $retired->delete();
    $insertOverride(Branch::factory()->for($otherCompany)->create(['name' => 'Foreign']), '"staff_confirm"');
    $insertOverride(Branch::factory()->for($company)->create(['name' => 'Wrong tenant row']), '"staff_confirm"', $otherCompany->id);
    $insertOverride(Branch::factory()->for($company)->create(['name' => 'Unrelated key']), '"staff_confirm"', key: 'unrelated');

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}/dine-in-round-mode")
        ->assertOk()
        ->assertExactJson(['data' => [
            'mode' => 'staff_confirm',
            'branches' => [
                ['uuid' => '10000000-0000-4000-8000-000000000001', 'name' => 'Alpha', 'mode' => 'kitchen_direct'],
                ['uuid' => '10000000-0000-4000-8000-000000000002', 'name' => 'Zulu', 'mode' => 'staff_confirm'],
            ],
        ]]);
});
