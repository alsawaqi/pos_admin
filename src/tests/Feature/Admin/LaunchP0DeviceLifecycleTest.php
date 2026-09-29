<?php

use App\Actions\Admin\AssertDeviceReadyToMove;
use App\Actions\Admin\ChangeDeviceAvailabilityAction;
use App\Actions\Admin\DecommissionDeviceAction;
use App\Actions\Admin\UnassignDeviceAction;
use App\Enums\DeviceStatus;
use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceActivationToken;
use App\Models\User;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function p0Device(): Device
{
    $company = Company::factory()->active()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    return Device::factory()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id,
        'status' => DeviceStatus::Active, 'device_token' => hash('sha256', 'test-device-token'),
        'token_company_id' => $company->id, 'token_branch_id' => $branch->id,
        'pending_outbox_count' => 0, 'outbox_reported_at' => now(), 'last_seen_at' => now(),
    ]);
}

it('revokes tokens and outstanding codes on unassign disable and decommission', function (string $operation) {
    $device = p0Device();
    $code = DeviceActivationToken::factory()->create(['device_id' => $device->id]);
    $actor = User::factory()->create();
    match ($operation) {
        'unassign' => app(UnassignDeviceAction::class)->handle($device, 'test move', $actor),
        'disable' => app(ChangeDeviceAvailabilityAction::class)->handle($device, 'disable', $actor),
        'decommission' => app(DecommissionDeviceAction::class)->handle($device, $actor),
    };
    $stored = DB::table('pos_devices')->where('id', $device->id)->first();
    expect($stored->device_token)->toBeNull()
        ->and($stored->token_company_id)->toBeNull()
        ->and($stored->token_branch_id)->toBeNull()
        ->and($code->fresh()->revoked_at)->not->toBeNull();
})->with(['unassign', 'disable', 'decommission']);

it('blocks unsent and stale or unknown outboxes without changing credentials', function (array $state) {
    $device = p0Device();
    $device->forceFill($state)->save();
    $token = $device->device_token;
    expect(fn () => app(UnassignDeviceAction::class)->handle($device))->toThrow(ValidationException::class);
    expect($device->fresh()->device_token)->toBe($token)
        ->and($device->fresh()->company_id)->toBe($device->company_id);
})->with([
    [['pending_outbox_count' => 2]],
    [['outbox_reported_at' => now()->subMinutes(16)]],
    [['pending_outbox_count' => null]],
]);

it('requires super admin and audits the written override reason', function () {
    $this->seed(PlatformRoleSeeder::class);
    $device = p0Device();
    $device->forceFill(['pending_outbox_count' => 7])->save();
    $actor = User::factory()->create();
    expect(fn () => app(AssertDeviceReadyToMove::class)->handle($device, $actor, 'Broken hardware'))
        ->toThrow(HttpException::class);
    $actor->assignRole(PlatformRole::SuperAdmin->value);
    app(UnassignDeviceAction::class)->handle($device, 'move', $actor, 'Broken hardware');
    $audit = DB::table('pos_audit_logs')->where('event', 'device.move_override')->first();
    expect(json_decode($audit->metadata, true))->toMatchArray([
        'reason' => 'Broken hardware', 'pending_outbox_count' => 7,
        'warning' => 'Unsent data will be quarantined, not delivered.',
    ]);
});

it('W1 exposes only outstanding activation metadata and revokes scoped codes with audit', function () {
    $this->seed(PlatformRoleSeeder::class);
    $actor = User::factory()->create();
    $actor->assignRole(PlatformRole::SuperAdmin->value);
    $device = p0Device();
    $code = DeviceActivationToken::factory()->create(['device_id' => $device->id]);
    $other = DeviceActivationToken::factory()->create();
    $this->actingAs($actor)->getJson("/admin/api/v1/devices/{$device->uuid}/activation-tokens")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.token_hash');
    $this->deleteJson("/admin/api/v1/devices/{$device->uuid}/activation-tokens/{$other->id}")->assertNotFound();
    $this->deleteJson("/admin/api/v1/devices/{$device->uuid}/activation-tokens/{$code->id}")->assertNoContent();
    expect($code->fresh()->revoked_at)->not->toBeNull()->and($other->fresh()->revoked_at)->toBeNull();
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'device.activation_token.revoked', 'auditable_id' => $code->id]);
});

it('W1 migration preserves an old release raw token through hashing and rollback revokes it', function () {
    $migration = require database_path('migrations/2026_09_30_000001_bind_and_hash_pos_device_credentials.php');
    $migration->down();
    $company = Company::factory()->active()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $device = Device::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'status' => DeviceStatus::Active, 'device_token' => 'mdev_existing-release']);
    $migration->up();
    $row = DB::table('pos_devices')->find($device->id);
    expect($row->device_token)->toBe(hash('sha256', 'mdev_existing-release'))
        ->and((int) $row->token_company_id)->toBe($company->id)->and((int) $row->token_branch_id)->toBe($branch->id);
    $migration->down();
    expect(DB::table('pos_devices')->find($device->id)->device_token)->toBeNull();
    $migration->up();
});
