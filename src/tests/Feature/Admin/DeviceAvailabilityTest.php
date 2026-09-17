<?php

declare(strict_types=1);

use App\Actions\Admin\AssignDeviceAction;
use App\Data\Admin\AssignDeviceData;
use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceAssignmentHistory;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $this->actor = User::factory()->create();
    $this->actor->assignRole(PlatformRole::SuperAdmin->value);
    $this->actingAs($this->actor);
});

function availabilityDevice(array $overrides = []): Device
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $bank = DB::table('banks')->insertGetId([
        'name' => 'Availability Test Bank', 'short_name' => 'TEST', 'swift_code' => 'TESTOMXX',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('pos_bank_softpos_profiles')->insert([
        'bank_id' => $bank, 'softpos_provider' => 'mosambee_muscat',
        'softpos_package' => 'com.mosambee.muscat.softpos', 'currency_code' => '0512',
        'refund_needs_transaction_id' => false, 'void_needs_session_id' => true,
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return Device::factory()->create(array_merge([
        'company_id' => $company->id, 'branch_id' => $branch->id,
        'bank_id' => $bank, 'terminal_id' => '00024182', 'terminal_pin' => 'test-secret',
        'device_token' => hash('sha256', 'test-device-'.$bank),
        'status' => DeviceStatus::Active, 'device_type' => DeviceType::PaymentStation,
        'metadata' => ['existing_setting' => 'keep'],
    ], $overrides));
}

it('disables visibly and idempotently without losing assignment, credentials or history', function (): void {
    $device = availabilityDevice();
    $before = $device->only(['company_id', 'branch_id', 'bank_id', 'terminal_id', 'terminal_pin', 'device_token', 'organization_id', 'commission_profile_id']);
    $history = DeviceAssignmentHistory::query()->create([
        'device_id' => $device->id, 'company_id' => $device->company_id,
        'branch_id' => $device->branch_id, 'assigned_at' => now(),
    ]);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/disable")
        ->assertOk()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.deleted_at', null);
    expect($device->refresh()->only(array_keys($before)))->toBe($before);
    expect($device->metadata['existing_setting'])->toBe('keep');
    expect($history->refresh()->unassigned_at)->toBeNull();
    $this->getJson('/admin/api/v1/devices?company_id='.$device->company_id)->assertJsonPath('data.0.uuid', $device->uuid);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/disable")->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'device.disabled')->count())->toBe(1);
});

it('reenables an enrolled device without requiring new enrollment', function (): void {
    $device = availabilityDevice();
    $token = $device->device_token;
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/disable")->assertOk();
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/enable")
        ->assertOk()->assertJsonPath('data.status', 'active');
    expect($device->refresh()->device_token)->toBe($token);
    expect($device->metadata)->toBe(['existing_setting' => 'keep']);
});

it('does not promote an assigned device to active when reenabling', function (): void {
    $device = availabilityDevice(['status' => DeviceStatus::Assigned]);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/disable")->assertOk();
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/enable")
        ->assertOk()->assertJsonPath('data.status', 'assigned');
});

it('keeps an unassigned device registered when reenabling', function (): void {
    $device = Device::factory()->create(['company_id' => null, 'branch_id' => null, 'status' => DeviceStatus::Registered]);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/disable")->assertOk();
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/enable")
        ->assertOk()->assertJsonPath('data.status', 'registered');
});

it('shows legacy archives and restores them only as disabled without erasing history', function (): void {
    $device = availabilityDevice();
    $history = DeviceAssignmentHistory::query()->create([
        'device_id' => $device->id, 'company_id' => $device->company_id,
        'branch_id' => $device->branch_id, 'assigned_at' => now()->subDay(),
        'unassigned_at' => now(), 'unassign_reason' => 'decommissioned',
    ]);
    $device->status = DeviceStatus::Blocked;
    $device->save();
    $device->delete();
    $this->getJson('/admin/api/v1/devices?company_id='.$device->company_id)->assertJsonPath('data.0.uuid', $device->uuid);
    $this->getJson("/admin/api/v1/devices/{$device->uuid}")->assertOk();
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/restore")
        ->assertOk()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.deleted_at', null);
    expect($history->refresh()->unassign_reason)->toBe('decommissioned');
    expect($device->refresh()->terminal_id)->toBe('00024182');
    expect($device->assignmentHistory()->whereNull('unassigned_at')->count())->toBe(1);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/restore")->assertOk();
    expect($device->assignmentHistory()->count())->toBe(2);
});

it('releases only a disabled terminal and allows its bank binding to be reused', function (): void {
    $device = availabilityDevice(['status' => DeviceStatus::Inactive]);
    $company = $device->company_id;
    $branch = $device->branch_id;
    $bank = $device->bank_id;
    $donation = $device->only(['commission_profile_id', 'organization_id']);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/release-terminal")
        ->assertOk()->assertJsonPath('data.bank_id', null)->assertJsonPath('data.terminal_id', null)
        ->assertJsonPath('data.terminal_pin', null)->assertJsonPath('data.status', 'inactive');
    expect($device->refresh()->company_id)->toBe($company);
    expect($device->branch_id)->toBe($branch);
    expect($device->only(array_keys($donation)))->toBe($donation);
    $other = Device::factory()->create();
    $this->postJson("/admin/api/v1/devices/{$other->uuid}/assign", [
        'company_id' => $company, 'branch_id' => $branch, 'bank_id' => $bank,
        'terminal_id' => '00024182', 'terminal_pin' => 'new-test-secret',
    ])->assertOk()->assertJsonPath('data.terminal_id', '00024182');
    $audit = DB::table('pos_audit_logs')->where('event', 'device.terminal_released')->first();
    expect($audit->old_values.$audit->new_values)->not->toContain('test-secret');
});

it('refuses terminal release from an enabled device', function (): void {
    $device = availabilityDevice();
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/release-terminal")
        ->assertUnprocessable()->assertJsonValidationErrors('device');
    expect($device->refresh()->terminal_id)->toBe('00024182');
});

it('returns a useful field error for a terminal reserved by an archived device', function (): void {
    $device = availabilityDevice();
    $device->delete();
    $other = Device::factory()->create();
    $response = $this->postJson("/admin/api/v1/devices/{$other->uuid}/assign", [
        'company_id' => $device->company_id, 'branch_id' => $device->branch_id,
        'bank_id' => $device->bank_id, 'terminal_id' => '00024182', 'terminal_pin' => 'request-secret',
    ])->assertUnprocessable()->assertJsonValidationErrors('terminal_id');
    expect($response->json('errors.terminal_id.0'))->toContain('release');
    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('request-secret');
});

it('rechecks terminal reservations inside the assignment transaction', function (): void {
    $device = availabilityDevice();
    $other = Device::factory()->create();
    $data = AssignDeviceData::from([
        'company_id' => $device->company_id, 'branch_id' => $device->branch_id,
        'bank_id' => $device->bank_id, 'terminal_id' => '00024182',
    ]);
    expect(fn () => app(AssignDeviceAction::class)->handle($other, $data, $this->actor))
        ->toThrow(ValidationException::class);
});

it('requires terminal configuration before enabling a station whose terminal was released', function (): void {
    $device = availabilityDevice(['status' => DeviceStatus::Inactive]);
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/release-terminal")->assertOk();
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/enable")
        ->assertUnprocessable()->assertJsonValidationErrors('terminal_id');
    expect($device->refresh()->status)->toBe(DeviceStatus::Inactive);
});

it('forbids availability changes without lifecycle permission', function (string $operation): void {
    $support = User::factory()->create();
    $support->assignRole(PlatformRole::Support->value);
    $this->actingAs($support);
    $device = availabilityDevice();
    if ($operation === 'restore') {
        $device->delete();
    }
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/availability/{$operation}")->assertForbidden();
})->with(['disable', 'enable', 'restore', 'release-terminal']);

it('excludes archived records from the unassigned device pool', function (): void {
    $device = Device::factory()->create(['company_id' => null, 'branch_id' => null]);
    $device->delete();
    $this->getJson('/admin/api/v1/devices?unassigned=1')->assertJsonCount(0, 'data');
});
