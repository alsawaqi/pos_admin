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
use App\Support\BranchLocationCheck;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        ->and((bool) DB::table('pos_branches')->where('id', $new->id)->value('location_check_enabled'))->toBeTrue()
        ->and(Schema::hasColumns('pos_branches', ['location_check_off_since', 'location_check_off_windows']))->toBeTrue()
        ->and(DB::table('pos_branches')->where('id', $existing->id)->value('location_check_off_since'))->toBeNull()
        ->and(DB::table('pos_branches')->where('id', $existing->id)->value('location_check_off_windows'))->toBeNull();
    expect(fn () => DB::table('pos_branches')->where('id', $new->id)->update(['location_check_enabled' => null]))
        ->toThrow(QueryException::class);
});

it('keeps every off period of the switch, the last 50, as the admin toggles it', function (): void {
    // The clock jumps hours between toggles: keep the admin session alive.
    config(['pos_admin_auth.session.idle_timeout_minutes' => 100000]);
    p5LocationAdmin($this);
    $branch = Branch::factory()->create();
    $toggle = fn (bool $on) => $this->patchJson("/admin/api/v1/branches/{$branch->uuid}", ['location_check_enabled' => $on])->assertOk();

    $this->travelTo(Carbon::parse('2026-10-05 08:00:00', 'UTC'));
    $toggle(false)->assertJsonPath('data.location_check_off_since', '2026-10-05T08:00:00+00:00');
    expect($branch->fresh()->location_check_off_windows)->toBeNull();

    $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    $toggle(true)->assertJsonPath('data.location_check_off_since', null);
    expect($branch->fresh()->location_check_off_windows)->toBe([
        ['from' => '2026-10-05T08:00:00+00:00', 'until' => '2026-10-05T10:00:00+00:00'],
    ]);

    // An unrelated save never adds a window.
    $this->patchJson("/admin/api/v1/branches/{$branch->uuid}", ['location_check_enabled' => true, 'name' => 'Renamed'])->assertOk();
    $this->travelTo(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
    $toggle(false);
    $this->travelTo(Carbon::parse('2026-10-05 13:00:00', 'UTC'));
    $toggle(true);
    expect($branch->fresh()->location_check_off_windows)->toBe([
        ['from' => '2026-10-05T08:00:00+00:00', 'until' => '2026-10-05T10:00:00+00:00'],
        ['from' => '2026-10-05T12:00:00+00:00', 'until' => '2026-10-05T13:00:00+00:00'],
    ]);

    // Capped at the newest 50.
    $old = array_map(fn (int $i): array => ['from' => sprintf('2026-09-01T%02d:00:00+00:00', $i % 24),
        'until' => sprintf('2026-09-01T%02d:30:00+00:00', $i % 24)], range(1, 50));
    DB::table('pos_branches')->where('id', $branch->id)->update(['location_check_off_windows' => json_encode($old)]);
    $this->travelTo(Carbon::parse('2026-10-05 14:00:00', 'UTC'));
    $toggle(false);
    $this->travelTo(Carbon::parse('2026-10-05 15:00:00', 'UTC'));
    $toggle(true);
    $windows = $branch->fresh()->location_check_off_windows;
    expect($windows)->toHaveCount(BranchLocationCheck::MAX_OFF_WINDOWS)
        ->and($windows[0])->toBe($old[1])
        ->and($windows[49])->toBe(['from' => '2026-10-05T14:00:00+00:00', 'until' => '2026-10-05T15:00:00+00:00']);

    // A branch created open is open from its creation.
    $created = $this->postJson('/admin/api/v1/branches', ['company_id' => $branch->company_id, 'name' => 'Open',
        'latitude' => 23.6143, 'longitude' => 58.4752, 'location_check_enabled' => false])->assertStatus(201);
    expect($created->json('data.location_check_off_since'))->toBe('2026-10-05T15:00:00+00:00');
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
