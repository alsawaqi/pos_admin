<?php

declare(strict_types=1);

/*
 * LAUNCH-P5 add-on (owner request 2026-10-05) — the branch "Location check"
 * switch: pos_branches.location_check_enabled (NOT NULL, default true; every
 * existing branch keeps today's rules). The admin branch form saves it on
 * create and edit, every change is audited with old and new values, and the
 * form shows it in English and Arabic. pos_api turns the location rules off
 * for a branch whose check is off.
 */

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(RefreshDatabase::class);

function p5LocationAdmin(TestCase $test): User
{
    $test->seed(PlatformRoleSeeder::class);
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

it('adds pos_branches.location_check_enabled, on for every existing and new branch', function (): void {
    $migration = require database_path('migrations/2026_10_05_100013_add_location_check_to_pos_branches.php');
    $migration->down();
    expect(Schema::hasColumn('pos_branches', 'location_check_enabled'))->toBeFalse();
    $existing = Branch::factory()->create();

    $migration->up();

    $new = Branch::factory()->create();
    expect((bool) DB::table('pos_branches')->where('id', $existing->id)->value('location_check_enabled'))->toBeTrue()
        ->and((bool) DB::table('pos_branches')->where('id', $new->id)->value('location_check_enabled'))->toBeTrue();
    expect(fn () => DB::table('pos_branches')->where('id', $new->id)->update(['location_check_enabled' => null]))
        ->toThrow(QueryException::class);
});

it('saves the switch on create and edit, and audits every change with old and new values', function (): void {
    $actor = p5LocationAdmin($this);
    $company = Company::factory()->create();

    $created = $this->postJson('/admin/api/v1/branches', [
        'company_id' => $company->id, 'name' => 'Open branch', 'latitude' => 23.6143, 'longitude' => 58.4752,
        'location_check_enabled' => false,
    ])->assertStatus(201)->assertJsonPath('data.location_check_enabled', false);
    $branch = Branch::query()->where('uuid', $created->json('data.uuid'))->firstOrFail();
    expect($branch->location_check_enabled)->toBeFalse();
    $createdLog = AuditLog::query()->where('event', 'branch.created')->where('branch_id', $branch->id)->sole();
    expect($createdLog->new_values['location_check_enabled'])->toBeFalse();

    // Default on when the form leaves it out.
    $this->postJson('/admin/api/v1/branches', [
        'company_id' => $company->id, 'name' => 'Fenced branch', 'latitude' => 23.6143, 'longitude' => 58.4752,
    ])->assertStatus(201)->assertJsonPath('data.location_check_enabled', true);

    $this->patchJson("/admin/api/v1/branches/{$branch->uuid}", ['location_check_enabled' => true])
        ->assertOk()->assertJsonPath('data.location_check_enabled', true);
    $this->patchJson("/admin/api/v1/branches/{$branch->uuid}", ['location_check_enabled' => false])
        ->assertOk()->assertJsonPath('data.location_check_enabled', false);

    $updates = AuditLog::query()->where('event', 'branch.updated')->where('branch_id', $branch->id)->orderBy('id')->get();
    expect($updates)->toHaveCount(2)
        ->and([$updates[0]->old_values['location_check_enabled'], $updates[0]->new_values['location_check_enabled']])->toBe([false, true])
        ->and([$updates[1]->old_values['location_check_enabled'], $updates[1]->new_values['location_check_enabled']])->toBe([true, false])
        ->and($updates[1]->actor_user_id)->toBe($actor->id);
    // The branch location is kept.
    expect([(float) $branch->fresh()->latitude, (float) $branch->fresh()->longitude])->toBe([23.6143, 58.4752]);

    $this->patchJson("/admin/api/v1/branches/{$branch->uuid}", ['location_check_enabled' => 'sometimes'])
        ->assertStatus(422)->assertJsonValidationErrors(['location_check_enabled']);
});

it('shows the Location check switch in the branch form, in English and Arabic', function (): void {
    $form = (string) file_get_contents(resource_path('js/Components/Admin/BranchFormModal.vue'));
    $types = (string) file_get_contents(resource_path('js/lib/api/branches.ts'));
    $en = json_decode((string) file_get_contents(resource_path('js/locales/en.json')), true, flags: JSON_THROW_ON_ERROR);
    $ar = json_decode((string) file_get_contents(resource_path('js/locales/ar.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($form)->toContain('v-model="form.location_check_enabled" type="checkbox"')
        ->toContain("t('branches.fields.location_check_enabled')")
        ->toContain("t('branches.form.location_check_help')")
        ->toContain('location_check_enabled: props.branch?.location_check_enabled ?? true')
        ->and(substr_count($form, 'location_check_enabled: form.location_check_enabled'))->toBe(2);
    expect($types)->toContain('location_check_enabled: boolean;')->toContain('location_check_enabled?: boolean;');
    expect($en['branches']['fields']['location_check_enabled'])->toBe('Location check')
        ->and($en['branches']['form']['location_check_help'])->toBe('Off: staff can log in and sell from any location. The branch location is kept.')
        ->and($ar['branches']['fields']['location_check_enabled'])->toBe('التحقق من الموقع')
        ->and($ar['branches']['form']['location_check_help'])->toBeString()->not->toBe('');
});
