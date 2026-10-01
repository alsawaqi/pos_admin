<?php

declare(strict_types=1);

/**
 * LAUNCH-P1 B1 (admin side) — device enrollment lock + location mode.
 *
 *  - P1-9  round-up commission profile + organization chosen at ASSIGN,
 *          cleared on unassign / move (never follow a device).
 *  - P1-12 editing serial or type revokes the credential (audited); serial
 *          normalised and unique after normalisation.
 *  - 2a    location mode 'branch' | 'any', chosen at assign and on the device
 *          page (audited); 'branch' needs a branch with coordinates.
 *  - lows  a new code revokes older unused codes; terminal ids unique after
 *          normalisation; a customer tablet may be assigned without a
 *          terminal; device page shows serial verification + refusals.
 */

use App\Actions\Admin\AssignDeviceAction;
use App\Actions\Admin\UpdateDeviceAction;
use App\Data\Admin\AssignDeviceData;
use App\Data\Admin\UpdateDeviceData;
use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceActivationToken;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\DeviceAssignment;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function p1bActingAs(string $role = 'device_operations'): User
{
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

function p1bBank(): int
{
    $bankId = (int) DB::table('banks')->insertGetId([
        'name' => 'P1 Bank', 'short_name' => 'P1B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('pos_bank_softpos_profiles')->insert([
        'bank_id' => $bankId, 'softpos_provider' => 'mosambee_muscat',
        'softpos_package' => 'com.mosambee.muscat.softpos', 'currency_code' => '0512',
        'refund_needs_transaction_id' => false, 'void_needs_session_id' => true,
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $bankId;
}

/** @param  array<string, mixed>  $attributes */
function p1bBranch(array $attributes = []): Branch
{
    return Branch::factory()->for(Company::factory()->active())->create($attributes + [
        'latitude' => 23.5880000, 'longitude' => 58.3829000, 'geofence_radius_m' => 500,
    ]);
}

/** A device that is assigned, activated and online. */
function p1bLiveDevice(Branch $branch, array $attributes = []): Device
{
    return Device::factory()->create($attributes + [
        'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'status' => DeviceStatus::Active,
        'device_token' => hash('sha256', 'p1b-live-token'), 'token_company_id' => $branch->company_id,
        'token_branch_id' => $branch->id, 'commission_profile_id' => DeviceAssignment::commissionProfile('Live profile'),
        'organization_id' => DeviceAssignment::organization('Live org'),
        'pending_outbox_count' => 0, 'outbox_reported_at' => now(), 'last_seen_at' => now(),
    ]);
}

// ============================ P1-9 =================================

it('P1-9 chooses the round-up settings at assign and refuses a new home without them', function (): void {
    p1bActingAs();
    $device = Device::factory()->create();
    $branch = p1bBranch();
    $payload = ['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'bank_id' => p1bBank(), 'terminal_id' => 'P1-TERM-1'];

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['commission_profile_id', 'organization_id']);
    expect($device->fresh()->branch_id)->toBeNull();

    $profile = DeviceAssignment::commissionProfile('Chosen at assign');
    $organization = DeviceAssignment::organization('Chosen org');
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", $payload + [
        'commission_profile_id' => $profile, 'organization_id' => $organization,
    ])->assertOk()
        ->assertJsonPath('data.commission_profile.name', 'Chosen at assign')
        ->assertJsonPath('data.organization.name', 'Chosen org')
        ->assertJsonPath('data.location_mode', 'branch');
    $audit = DB::table('pos_audit_logs')->where('event', 'device.assigned')->latest('id')->first();
    expect(json_decode($audit->new_values, true))->toMatchArray([
        'commission_profile_id' => $profile, 'organization_id' => $organization, 'location_mode' => 'branch',
    ]);
});

it('P1-9 never carries round-up settings to another merchant or branch', function (): void {
    $actor = p1bActingAs();
    $from = p1bBranch();
    $device = p1bLiveDevice($from);
    $old = $device->only(['commission_profile_id', 'organization_id']);
    $to = p1bBranch();

    expect(fn () => app(AssignDeviceAction::class)->handle($device, AssignDeviceData::from([
        'company_id' => $to->company_id, 'branch_id' => $to->id, 'bank_id' => p1bBank(), 'terminal_id' => 'P1-MOVE',
    ]), $actor))->toThrow(ValidationException::class);
    expect($device->fresh()->only(array_keys($old)))->toBe($old)
        ->and($device->fresh()->branch_id)->toBe($from->id);

    $new = ['commission_profile_id' => DeviceAssignment::commissionProfile('New merchant'),
        'organization_id' => DeviceAssignment::organization('New org')];
    app(AssignDeviceAction::class)->handle($device, AssignDeviceData::from([
        'company_id' => $to->company_id, 'branch_id' => $to->id, 'bank_id' => p1bBank(), 'terminal_id' => 'P1-MOVE',
    ] + $new), $actor);
    expect($device->fresh()->only(array_keys($new)))->toBe($new);
});

it('P1-9 clears the round-up settings and the location mode on unassign', function (): void {
    p1bActingAs();
    $branch = p1bBranch();
    $device = p1bLiveDevice($branch, ['location_mode' => 'any', 'location_mode_since' => now(), 'location_any_started_at' => now(),
        'serial_verified_at' => now()]);

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/unassign", ['reason' => 'back to pool'])->assertOk()
        ->assertJsonPath('data.commission_profile_id', null)->assertJsonPath('data.organization_id', null)
        ->assertJsonPath('data.location_mode', 'branch')->assertJsonPath('data.serial_verified_at', null);
    $row = DB::table('pos_devices')->find($device->id);
    expect([$row->commission_profile_id, $row->organization_id, $row->location_mode_since, $row->location_any_started_at])
        ->toBe([null, null, null, null]);
});

it('P1-9 keeps the round-up settings on a same-branch bank edit and refuses them on a pooled device', function (): void {
    p1bActingAs();
    $branch = p1bBranch();
    $device = p1bLiveDevice($branch, ['bank_id' => p1bBank(), 'terminal_id' => 'P1-SAME']);
    $kept = $device->only(['commission_profile_id', 'organization_id']);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'bank_id' => $device->bank_id,
        'terminal_id' => 'P1-SAME-2', 'terminal_pin' => '1234',
    ])->assertOk();
    expect($device->fresh()->only(array_keys($kept)))->toBe($kept)
        ->and($device->fresh()->status)->toBe(DeviceStatus::Active);

    $pooled = Device::factory()->create();
    $this->patchJson("/admin/api/v1/devices/{$pooled->uuid}", ['commission_profile_id' => DeviceAssignment::commissionProfile()])
        ->assertUnprocessable()->assertJsonValidationErrors(['commission_profile_id']);
});

it('P1-9 migration clears pooled devices, keeps assigned ones and audits what it cleared', function (): void {
    $branch = p1bBranch();
    $assigned = p1bLiveDevice($branch);
    $kept = $assigned->only(['commission_profile_id', 'organization_id']);
    $pooled = Device::factory()->create();
    DB::table('pos_devices')->where('id', $pooled->id)->update([
        'commission_profile_id' => DeviceAssignment::commissionProfile('Stale'), 'organization_id' => DeviceAssignment::organization('Stale org'),
    ]);
    $stale = DB::table('pos_devices')->where('id', $pooled->id)->first(['commission_profile_id', 'organization_id']);

    (require database_path('migrations/2026_10_01_100200_clear_pool_device_donation_bindings.php'))->up();

    expect($assigned->fresh()->only(array_keys($kept)))->toBe($kept);
    expect($pooled->fresh()->commission_profile_id)->toBeNull()->and($pooled->fresh()->organization_id)->toBeNull();
    $audit = DB::table('pos_audit_logs')->where('event', 'device.donation_bindings_cleared')->sole();
    expect((int) $audit->auditable_id)->toBe($pooled->id)
        ->and(json_decode($audit->old_values, true))->toBe(['commission_profile_id' => $stale->commission_profile_id, 'organization_id' => $stale->organization_id]);
});

// ============================ P1-12 ================================

it('P1-12 editing the serial or the type revokes the credential and outstanding codes and is audited', function (string $field): void {
    $actor = p1bActingAs();
    $device = p1bLiveDevice(p1bBranch(), ['serial_number' => 'T3-OLD-1', 'serial_verified_at' => now()]);
    $code = DeviceActivationToken::factory()->create(['device_id' => $device->id]);
    $payload = $field === 'serial_number' ? ['serial_number' => 'T3-NEW-1'] : ['device_type' => DeviceType::Handheld->value];

    $this->patchJson("/admin/api/v1/devices/{$device->uuid}", $payload)->assertOk()
        ->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.serial_verified_at', null);

    $row = DB::table('pos_devices')->find($device->id);
    expect($row->device_token)->toBeNull()->and($row->token_company_id)->toBeNull()
        ->and($code->fresh()->revoked_at)->not->toBeNull();
    $audit = DB::table('pos_audit_logs')->where('event', 'device.credentials_revoked')->sole();
    expect((int) $audit->actor_user_id)->toBe($actor->id)
        ->and(json_decode($audit->metadata, true))->toMatchArray(['changed' => [$field], 'had_device_token' => true]);
})->with(['serial_number', 'device_type']);

it('P1-12 leaves the credential alone for other edits and for the same serial written differently', function (): void {
    p1bActingAs();
    $device = p1bLiveDevice(p1bBranch(), ['serial_number' => 'T3-KEEP-1']);

    $this->patchJson("/admin/api/v1/devices/{$device->uuid}", ['serial_number' => ' t3-keep-1 ', 'name' => 'Front till'])
        ->assertOk()->assertJsonPath('data.status', 'active');

    expect($device->fresh()->device_token)->toBe(hash('sha256', 'p1b-live-token'))
        ->and($device->fresh()->serial_number)->toBe('T3-KEEP-1');
    expect(DB::table('pos_audit_logs')->where('event', 'device.credentials_revoked')->exists())->toBeFalse();
});

it('P1-12 keeps serials unique after normalisation on register and edit', function (): void {
    p1bActingAs();
    Device::factory()->create(['serial_number' => 'G7-SN-77']);
    $other = Device::factory()->create(['serial_number' => 'Z92-SN-1']);

    $this->patchJson("/admin/api/v1/devices/{$other->uuid}", ['serial_number' => 'g7-sn-7 7'])
        ->assertUnprocessable()->assertJsonValidationErrors(['serial_number']);
    expect(fn () => app(UpdateDeviceAction::class)->handle($other, UpdateDeviceData::from(['serial_number' => ' g7-SN-77 '])))
        ->toThrow(QueryException::class);
});

it('P1-12 migration normalises stored serials and refuses to merge two devices', function (): void {
    $migration = require database_path('migrations/2026_10_01_100000_add_device_serial_lock_and_location_mode.php');
    $migration->down();
    $a = Device::factory()->create(['serial_number' => ' t3 aa-1 ']);
    $migration->up();
    expect($a->fresh()->serial_number)->toBe('T3AA-1');

    $migration->down();
    Device::factory()->create(['serial_number' => 't3aa-1']);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'collide after normalisation');
    expect(Schema::hasColumn('pos_devices', 'location_mode'))->toBeFalse();
    DB::table('pos_devices')->where('serial_number', 't3aa-1')->delete();
    $migration->up();
});

// ============================ 2a ===================================

it('2a assign defaults to branch and refuses branch mode at a branch without coordinates', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 09:00:00'));
    p1bActingAs();
    $open = p1bBranch(['latitude' => null, 'longitude' => null]);
    $device = Device::factory()->create();
    $payload = ['company_id' => $open->company_id, 'branch_id' => $open->id, 'bank_id' => p1bBank(), 'terminal_id' => 'P1-OPEN',
        'commission_profile_id' => DeviceAssignment::commissionProfile(), 'organization_id' => DeviceAssignment::organization()];

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['location_mode']);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", $payload + ['location_mode' => 'branch'])
        ->assertUnprocessable()->assertJsonValidationErrors(['location_mode']);
    expect($device->fresh()->branch_id)->toBeNull();

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", $payload + ['location_mode' => 'any'])->assertOk()
        ->assertJsonPath('data.location_mode', 'any');
    $row = DB::table('pos_devices')->find($device->id);
    expect(Carbon::parse($row->location_mode_since)->toDateTimeString())->toBe('2026-10-01 09:00:00')
        ->and(Carbon::parse($row->location_any_started_at)->toDateTimeString())->toBe('2026-10-01 09:00:00');
});

it('2a the device page switches the mode, keeps the any window and audits it', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 10:00:00'));
    $actor = p1bActingAs();
    $branch = p1bBranch();
    $device = p1bLiveDevice($branch);

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/location-mode", ['location_mode' => 'any'])->assertOk()
        ->assertJsonPath('data.location_mode', 'any');
    $this->travelTo(Carbon::parse('2026-10-01 10:10:00'));
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/location-mode", ['location_mode' => 'branch'])->assertOk()
        ->assertJsonPath('data.location_mode', 'branch');

    $row = DB::table('pos_devices')->find($device->id);
    expect(Carbon::parse($row->location_any_started_at)->toDateTimeString())->toBe('2026-10-01 10:00:00')
        ->and(Carbon::parse($row->location_mode_since)->toDateTimeString())->toBe('2026-10-01 10:10:00')
        ->and($row->device_token)->toBe(hash('sha256', 'p1b-live-token'));
    $audits = DB::table('pos_audit_logs')->where('event', 'device.location_mode_changed')->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and((int) $audits[0]->actor_user_id)->toBe($actor->id)
        ->and(json_decode($audits[1]->old_values, true)['location_mode'])->toBe('any')
        ->and(json_decode($audits[1]->new_values, true)['location_mode'])->toBe('branch');

    // Same mode again is a no-op (no extra audit row).
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/location-mode", ['location_mode' => 'branch'])->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'device.location_mode_changed')->count())->toBe(2);
});

it('2a the device page refuses branch mode without coordinates, pooled devices and other roles', function (): void {
    p1bActingAs();
    $open = p1bBranch(['latitude' => null, 'longitude' => null]);
    $device = p1bLiveDevice($open, ['location_mode' => 'any']);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/location-mode", ['location_mode' => 'branch'])
        ->assertUnprocessable()->assertJsonValidationErrors(['location_mode']);
    expect($device->fresh()->location_mode)->toBe('any');

    $pooled = Device::factory()->create();
    $this->postJson("/admin/api/v1/devices/{$pooled->uuid}/location-mode", ['location_mode' => 'any'])
        ->assertUnprocessable()->assertJsonValidationErrors(['location_mode']);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/location-mode", ['location_mode' => 'nowhere'])
        ->assertUnprocessable();

    p1bActingAs(PlatformRole::Support->value);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/location-mode", ['location_mode' => 'any'])->assertForbidden();
});

it('2a a branch cannot lose its coordinates in admin, so a branch-mode device stays fenced', function (): void {
    p1bActingAs(PlatformRole::SuperAdmin->value);
    $branch = p1bBranch();
    p1bLiveDevice($branch);
    $this->patchJson("/admin/api/v1/branches/{$branch->uuid}", ['latitude' => null, 'longitude' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
    expect($branch->fresh()->latitude)->not->toBeNull();
});

it('2a migration moves devices at branches without coordinates to any', function (): void {
    $migration = require database_path('migrations/2026_10_01_100000_add_device_serial_lock_and_location_mode.php');
    $migration->down();
    $fenced = p1bLiveDevice(p1bBranch());
    $open = Device::factory()->create(['serial_number' => 'OPEN-1', 'company_id' => ($b = p1bBranch(['latitude' => null]))->company_id, 'branch_id' => $b->id]);
    $migration->up();
    expect(DB::table('pos_devices')->find($fenced->id)->location_mode)->toBe('branch')
        ->and(DB::table('pos_devices')->find($open->id)->location_mode)->toBe('any')
        ->and(DB::table('pos_devices')->find($open->id)->location_mode_since)->not->toBeNull();
});

// ============================ lows =================================

it('a new activation code revokes the older unused codes of that device', function (): void {
    p1bActingAs();
    $device = p1bLiveDevice(p1bBranch());
    $older = DeviceActivationToken::factory()->count(2)->create(['device_id' => $device->id]);
    $used = DeviceActivationToken::factory()->create(['device_id' => $device->id, 'used_at' => now()->subDay()]);
    $otherDevice = DeviceActivationToken::factory()->create();

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/activation-token")->assertCreated();

    expect($older->every(fn ($code) => $code->fresh()->revoked_at !== null))->toBeTrue()
        ->and($used->fresh()->revoked_at)->toBeNull()
        ->and($otherDevice->fresh()->revoked_at)->toBeNull();
    $this->getJson("/admin/api/v1/devices/{$device->uuid}/activation-tokens")->assertOk()->assertJsonCount(1, 'data');
    $audit = DB::table('pos_audit_logs')->where('event', 'device.activation_token.created')->sole();
    expect(json_decode($audit->metadata, true)['revoked_previous_codes'])->toBe(2);
});

it('terminal ids are unique per bank after normalisation', function (): void {
    p1bActingAs();
    $branch = p1bBranch();
    $bank = p1bBank();
    p1bLiveDevice($branch, ['bank_id' => $bank, 'terminal_id' => 'TID 0042']);
    $device = Device::factory()->create();

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'bank_id' => $bank, 'terminal_id' => ' tid0042 ',
        ...DeviceAssignment::extras(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['terminal_id']);
    expect($device->fresh()->branch_id)->toBeNull();
});

it('a customer tablet may be assigned without a bank terminal, other types may not', function (): void {
    p1bActingAs();
    $branch = p1bBranch();
    $tablet = Device::factory()->create(['device_type' => DeviceType::CustomerTablet]);
    $till = Device::factory()->create();
    $payload = ['company_id' => $branch->company_id, 'branch_id' => $branch->id];

    $this->postJson("/admin/api/v1/devices/{$tablet->uuid}/assign", $payload + DeviceAssignment::extras('branch'))
        ->assertOk()->assertJsonPath('data.bank_id', null)->assertJsonPath('data.terminal_id', null)
        ->assertJsonPath('data.status', 'assigned');
    $this->postJson("/admin/api/v1/devices/{$till->uuid}/assign", $payload + DeviceAssignment::extras('branch'))
        ->assertUnprocessable()->assertJsonValidationErrors(['bank_id', 'terminal_id']);
    // A tablet that does get a terminal still needs both halves.
    $other = Device::factory()->create(['device_type' => DeviceType::CustomerTablet]);
    $this->postJson("/admin/api/v1/devices/{$other->uuid}/assign", $payload + ['terminal_id' => 'TAB-1'] + DeviceAssignment::extras())
        ->assertUnprocessable()->assertJsonValidationErrors(['bank_id']);
});

it('the device page shows serial verification, the location mode and recent refused activations', function (): void {
    p1bActingAs();
    $device = p1bLiveDevice(p1bBranch(), ['serial_verified_at' => Carbon::parse('2026-10-01 08:00:00')]);
    foreach (range(1, 12) as $i) {
        DB::table('pos_device_activation_attempts')->insert([
            'device_id' => $device->id, 'outcome' => 'refused', 'reason' => 'activation_device_mismatch', 'binding_mode' => 'enforce',
            'reported_serial_masked' => '******'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'reported_serial_hash' => hash('sha256', 'S'.$i),
            'app' => 'till', 'manufacturer' => 'ZCS', 'model' => 'G7', 'ip_address' => '198.51.100.7', 'created_at' => now(),
        ]);
    }

    $this->getJson("/admin/api/v1/devices/{$device->uuid}")->assertOk()
        ->assertJsonPath('data.serial_verified_at', '2026-10-01T08:00:00+00:00')
        ->assertJsonPath('data.location_mode', 'branch')
        ->assertJsonCount(10, 'data.activation_refusals')
        ->assertJsonPath('data.activation_refusals.0.reported_serial', '******0012')
        ->assertJsonPath('data.activation_refusals.0.reason', 'activation_device_mismatch')
        ->assertJsonMissingPath('data.activation_refusals.0.reported_serial_hash');
});

// ===================== follow-up (coordinator 2026-10-01) =====================

it('a customer tablet needs no round-up settings at assign, and old ones never follow it', function (): void {
    p1bActingAs();
    [$first, $second] = [p1bBranch(), p1bBranch()];
    $tablet = Device::factory()->create(['device_type' => DeviceType::CustomerTablet]);

    $this->postJson("/admin/api/v1/devices/{$tablet->uuid}/assign", [
        'company_id' => $first->company_id, 'branch_id' => $first->id, 'location_mode' => 'branch',
    ])->assertOk()->assertJsonPath('data.commission_profile_id', null)->assertJsonPath('data.organization_id', null);

    // Even if it carried settings, a move to another merchant drops them.
    $tablet->forceFill(['commission_profile_id' => DeviceAssignment::commissionProfile('Old'),
        'organization_id' => DeviceAssignment::organization('Old org'), 'pending_outbox_count' => 0,
        'outbox_reported_at' => now()])->save();
    $this->postJson("/admin/api/v1/devices/{$tablet->uuid}/assign", [
        'company_id' => $second->company_id, 'branch_id' => $second->id, 'location_mode' => 'branch',
    ])->assertOk()->assertJsonPath('data.branch_id', $second->id)
        ->assertJsonPath('data.commission_profile_id', null)->assertJsonPath('data.organization_id', null);

    // Other types are unchanged: a handheld still needs them.
    $handheld = Device::factory()->create(['device_type' => DeviceType::Handheld]);
    $this->postJson("/admin/api/v1/devices/{$handheld->uuid}/assign", [
        'company_id' => $first->company_id, 'branch_id' => $first->id, 'bank_id' => p1bBank(), 'terminal_id' => 'HH-1',
        'location_mode' => 'branch',
    ])->assertUnprocessable()->assertJsonValidationErrors(['commission_profile_id', 'organization_id']);
});

it('the assign geofence radius override is limited to 500-2000 m like branches', function (int $radius, bool $accepted): void {
    p1bActingAs();
    $branch = p1bBranch(['geofence_radius_m' => 700]);
    $device = Device::factory()->create();

    $response = $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'bank_id' => p1bBank(), 'terminal_id' => 'RAD-'.$radius,
        'geofence_radius_m' => $radius, ...DeviceAssignment::extras('branch'),
    ]);

    if ($accepted) {
        $response->assertOk();
        expect($branch->fresh()->geofence_radius_m)->toBe($radius);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors(['geofence_radius_m']);
        expect($branch->fresh()->geofence_radius_m)->toBe(700)->and($device->fresh()->branch_id)->toBeNull();
    }
})->with([[100, false], [499, false], [500, true], [2000, true], [2001, false]]);
