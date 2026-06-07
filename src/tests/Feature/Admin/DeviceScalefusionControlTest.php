<?php

declare(strict_types=1);

/**
 * Feature tests for the live scalefusion device surface
 * (DeviceScalefusionController): detail read, the remote control
 * actions, permission gating (DevicesControl), the no-kiosk-id guard,
 * audit logging, and GPS route normalisation. All scalefusion HTTP is
 * faked.
 */

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    config([
        'services.scalefusion.token' => 'test-token',
        'services.scalefusion.base_v3' => 'https://scalefusion.test/api/v3',
        'services.scalefusion.base_v1' => 'https://scalefusion.test/api/v1',
    ]);
});

function actingAsSfRole(TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

it('returns live scalefusion detail for a device', function (): void {
    actingAsSfRole($this, PlatformRole::DeviceOperations->value);
    Http::fake([
        'scalefusion.test/api/v3/devices/55001.json' => Http::response(['device' => ['id' => 55001, 'total_ram_size' => 8192, 'os_version' => '13']]),
    ]);
    $device = Device::factory()->create(['kiosk_id' => '55001']);

    $this->getJson("/admin/api/v1/devices/{$device->uuid}/scalefusion")
        ->assertOk()
        ->assertJsonPath('data.device.total_ram_size', 8192);
});

it('reboots a device, hits the v1 endpoint, and writes an audit row', function (): void {
    actingAsSfRole($this, PlatformRole::DeviceOperations->value);
    Http::fake(['*' => Http::response(['success' => true])]);
    $device = Device::factory()->create(['kiosk_id' => '55001']);

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/scalefusion/reboot")
        ->assertOk()
        ->assertJsonPath('ok', true);

    Http::assertSent(fn ($req) => $req->method() === 'PUT' && str_contains($req->url(), '/api/v1/devices/55001/reboot.json'));
    expect(AuditLog::query()->where('event', 'device.scalefusion.reboot')->where('auditable_id', $device->id)->exists())->toBeTrue();
});

it('runs a destructive factory_reset action through the control gate', function (): void {
    actingAsSfRole($this, PlatformRole::DeviceOperations->value);
    Http::fake(['*' => Http::response(['success' => true])]);
    $device = Device::factory()->create(['kiosk_id' => '55001']);

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/scalefusion/action", [
        'action_type' => 'factory_reset',
        'wipe_sd_card' => true,
    ])->assertOk();

    Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v1/devices/actions.json?device_ids%5B%5D=55001')
        && str_contains((string) $req->body(), 'action_type=factory_reset')
        && str_contains((string) $req->body(), 'wipe_sd_card=true'));
    expect(AuditLog::query()->where('event', 'device.scalefusion.action:factory_reset')->exists())->toBeTrue();
});

it('forbids control actions for a role without devices.control', function (): void {
    actingAsSfRole($this, PlatformRole::Support->value);
    Http::fake();
    $device = Device::factory()->create(['kiosk_id' => '55001']);

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/scalefusion/reboot")->assertForbidden();
    Http::assertNothingSent();
});

it('still lets a view-only role read the detail', function (): void {
    actingAsSfRole($this, PlatformRole::Support->value);
    Http::fake(['*' => Http::response(['device' => ['id' => 55001]])]);
    $device = Device::factory()->create(['kiosk_id' => '55001']);

    $this->getJson("/admin/api/v1/devices/{$device->uuid}/scalefusion")->assertOk();
});

it('returns 422 when the device has no kiosk_id', function (): void {
    actingAsSfRole($this, PlatformRole::DeviceOperations->value);
    Http::fake();
    $device = Device::factory()->create(['kiosk_id' => null]);

    $this->postJson("/admin/api/v1/devices/{$device->uuid}/scalefusion/reboot")->assertStatus(422);
    Http::assertNothingSent();
});

it('returns normalised location route points oldest-first', function (): void {
    actingAsSfRole($this, PlatformRole::DeviceOperations->value);
    Http::fake([
        'scalefusion.test/api/v1/devices/55001/locations.json*' => Http::response([
            ['latitude' => 23.6, 'longitude' => 58.5, 'date_time' => 200, 'address' => 'B'],
            ['latitude' => 23.5, 'longitude' => 58.4, 'date_time' => 100, 'address' => 'A'],
        ]),
    ]);
    $device = Device::factory()->create(['kiosk_id' => '55001']);

    $this->getJson("/admin/api/v1/devices/{$device->uuid}/scalefusion/locations?date=2026-06-16")
        ->assertOk()
        ->assertJsonPath('data.0.address', 'A')
        ->assertJsonPath('data.1.address', 'B');
});
