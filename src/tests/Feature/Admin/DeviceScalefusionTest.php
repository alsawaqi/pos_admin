<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\Device;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    Cache::flush();
    config([
        'services.scalefusion.token' => 'test-token',
        'services.scalefusion.base_v3' => 'https://scalefusion.test/api/v3',
    ]);
});

function actingAsDeviceAdmin(\Tests\TestCase $test): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

it('merges scalefusion status onto devices by kiosk_id', function (): void {
    actingAsDeviceAdmin($this);

    Http::fake([
        '*' => Http::response([
            'devices' => [
                ['device' => [
                    'id' => 778899,
                    'name' => 'POS-Live',
                    'battery_status' => 88,
                    'battery_charging' => true,
                    'connection_state' => 'active',
                    'connection_status' => 'online',
                    'device_status' => 'ready',
                    'locked' => false,
                    'last_seen_on' => '2026-06-16T10:00:00Z',
                    'location' => ['lat' => 23.6, 'lng' => 58.5],
                ]],
            ],
            'next_cursor' => null,
        ]),
    ]);

    Device::factory()->create(['kiosk_id' => '778899']);

    $response = $this->getJson('/admin/api/v1/devices?with_scalefusion=1')->assertOk();

    $response->assertJsonPath('data.0.scalefusion.connection_status', 'online');
    $response->assertJsonPath('data.0.scalefusion.battery_status', 88);
    $response->assertJsonPath('data.0.scalefusion.location.lat', 23.6);
});

it('returns a null scalefusion entry when the kiosk_id is unknown to scalefusion', function (): void {
    actingAsDeviceAdmin($this);

    Http::fake(['*' => Http::response(['devices' => [], 'next_cursor' => null])]);
    Device::factory()->create(['kiosk_id' => 'not-enrolled']);

    $this->getJson('/admin/api/v1/devices?with_scalefusion=1')
        ->assertOk()
        ->assertJsonPath('data.0.scalefusion', null);
});

it('degrades to a null scalefusion entry when scalefusion is unreachable', function (): void {
    actingAsDeviceAdmin($this);

    Http::fake(['*' => Http::response('upstream error', 500)]);
    Device::factory()->create(['kiosk_id' => '778899']);

    $this->getJson('/admin/api/v1/devices?with_scalefusion=1')
        ->assertOk()
        ->assertJsonPath('data.0.scalefusion', null);
});

it('omits scalefusion and calls nothing when the flag is absent', function (): void {
    actingAsDeviceAdmin($this);

    Http::fake();
    Device::factory()->create(['kiosk_id' => '778899']);

    $response = $this->getJson('/admin/api/v1/devices')->assertOk();

    expect($response->json('data.0'))->not->toHaveKey('scalefusion');
    Http::assertNothingSent();
});
