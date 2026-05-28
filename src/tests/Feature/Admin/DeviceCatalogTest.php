<?php

declare(strict_types=1);

/**
 * Feature tests for the Device Makes + Models catalogue endpoints
 * (Sprint 1.4). Covers:
 *
 *   - Makes CRUD: create, list (excludes inactive by default),
 *     delete refused when devices still reference it.
 *   - Models CRUD: create under a make, list scoped to make, delete
 *     refused when devices still reference it.
 *   - Scoped binding: /device-makes/A/models/{model-of-B} → 404.
 *   - Permission gate: Support role can list, can't create.
 */

use App\Enums\PlatformRole;
use App\Models\Device;
use App\Models\DeviceMake;
use App\Models\DeviceModel;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

/**
 * Helper — log in as a platform admin with the given role.
 */
function actingAsCatalogRole(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

// ============================ MAKES ================================

it('lists active makes by default', function (): void {
    actingAsCatalogRole($this, PlatformRole::Support->value);

    DeviceMake::factory()->create(['name' => 'Sunmi']);
    DeviceMake::factory()->inactive()->create(['name' => 'OldVendor']);

    $response = $this->getJson('/admin/api/v1/device-makes')->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Sunmi');
});

it('includes inactive makes when requested', function (): void {
    actingAsCatalogRole($this, PlatformRole::DeviceOperations->value);

    DeviceMake::factory()->create();
    DeviceMake::factory()->inactive()->create();

    $response = $this->getJson('/admin/api/v1/device-makes?include_inactive=1')->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('creates a make for users with DeviceModelsManage', function (): void {
    actingAsCatalogRole($this, PlatformRole::DeviceOperations->value);

    $this->postJson('/admin/api/v1/device-makes', [
        'name' => 'PAX',
        'display_order' => 1,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'PAX')
        ->assertJsonPath('data.display_order', 1);

    $this->assertDatabaseHas('pos_device_makes', ['name' => 'PAX']);
});

it('forbids Support role from creating a make', function (): void {
    actingAsCatalogRole($this, PlatformRole::Support->value);

    $this->postJson('/admin/api/v1/device-makes', [
        'name' => 'PAX',
    ])->assertForbidden();
});

it('rejects duplicate make names', function (): void {
    actingAsCatalogRole($this, PlatformRole::DeviceOperations->value);

    DeviceMake::factory()->create(['name' => 'Sunmi']);

    $this->postJson('/admin/api/v1/device-makes', ['name' => 'Sunmi'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('refuses to delete a make that is still in use by a device', function (): void {
    actingAsCatalogRole($this, PlatformRole::DeviceOperations->value);

    $make = DeviceMake::factory()->create();
    $model = DeviceModel::factory()->for($make, 'make')->create();
    Device::factory()->create(['make_id' => $make->id, 'model_id' => $model->id]);

    $this->deleteJson("/admin/api/v1/device-makes/{$make->id}")
        ->assertStatus(409)
        ->assertJsonFragment(['message' => 'Cannot delete a device make that is still in use by one or more devices. Deactivate it instead.']);

    $this->assertDatabaseHas('pos_device_makes', ['id' => $make->id]);
});

it('refuses to delete a make that still has models', function (): void {
    actingAsCatalogRole($this, PlatformRole::DeviceOperations->value);

    $make = DeviceMake::factory()->create();
    DeviceModel::factory()->for($make, 'make')->create();

    $this->deleteJson("/admin/api/v1/device-makes/{$make->id}")
        ->assertStatus(409);
});

// ============================ MODELS ===============================

it('lists models scoped to the URL-bound make', function (): void {
    actingAsCatalogRole($this, PlatformRole::Support->value);

    $sunmi = DeviceMake::factory()->create(['name' => 'Sunmi']);
    $pax = DeviceMake::factory()->create(['name' => 'PAX']);
    DeviceModel::factory()->for($sunmi, 'make')->create(['name' => 'P2 Mini']);
    DeviceModel::factory()->for($sunmi, 'make')->create(['name' => 'P2 Lite']);
    DeviceModel::factory()->for($pax, 'make')->create(['name' => 'A920']);

    $response = $this->getJson("/admin/api/v1/device-makes/{$sunmi->id}/models")->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('creates a model under the URL-bound make', function (): void {
    actingAsCatalogRole($this, PlatformRole::DeviceOperations->value);

    $make = DeviceMake::factory()->create();

    $this->postJson("/admin/api/v1/device-makes/{$make->id}/models", [
        'name' => 'P2 Mini',
        'code' => 'P2-MINI',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'P2 Mini')
        ->assertJsonPath('data.code', 'P2-MINI')
        ->assertJsonPath('data.make_id', $make->id);
});

it('rejects duplicate model names within the same make', function (): void {
    actingAsCatalogRole($this, PlatformRole::DeviceOperations->value);

    $make = DeviceMake::factory()->create();
    DeviceModel::factory()->for($make, 'make')->create(['name' => 'P2 Mini']);

    $this->postJson("/admin/api/v1/device-makes/{$make->id}/models", ['name' => 'P2 Mini'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('allows the same model name across different makes', function (): void {
    actingAsCatalogRole($this, PlatformRole::DeviceOperations->value);

    $sunmi = DeviceMake::factory()->create();
    $pax = DeviceMake::factory()->create();
    DeviceModel::factory()->for($sunmi, 'make')->create(['name' => 'Pro']);

    $this->postJson("/admin/api/v1/device-makes/{$pax->id}/models", ['name' => 'Pro'])
        ->assertCreated();
});

it('returns 404 when fetching a model under the wrong make', function (): void {
    actingAsCatalogRole($this, PlatformRole::DeviceOperations->value);

    $sunmi = DeviceMake::factory()->create();
    $pax = DeviceMake::factory()->create();
    $sunmiModel = DeviceModel::factory()->for($sunmi, 'make')->create();

    // PATCH the Sunmi model via PAX's URL → scopeBindings 404.
    $this->patchJson("/admin/api/v1/device-makes/{$pax->id}/models/{$sunmiModel->id}", [
        'name' => 'Hacked',
    ])->assertNotFound();
});

it('refuses to delete a model that is still in use by a device', function (): void {
    actingAsCatalogRole($this, PlatformRole::DeviceOperations->value);

    $make = DeviceMake::factory()->create();
    $model = DeviceModel::factory()->for($make, 'make')->create();
    Device::factory()->create(['make_id' => $make->id, 'model_id' => $model->id]);

    $this->deleteJson("/admin/api/v1/device-makes/{$make->id}/models/{$model->id}")
        ->assertStatus(409);
});
