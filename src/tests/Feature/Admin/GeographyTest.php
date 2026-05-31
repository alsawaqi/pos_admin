<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\District;
use App\Models\Geo\Region;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function actingAsGeoRole(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

it('lists active countries by default', function (): void {
    actingAsGeoRole($this, PlatformRole::Support->value);

    Country::factory()->create(['name' => 'Oman']);
    Country::factory()->inactive()->create(['name' => 'Atlantis']);

    $response = $this->getJson('/admin/api/v1/countries')->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Oman');
});

it('creates a country for a super admin', function (): void {
    actingAsGeoRole($this, PlatformRole::SuperAdmin->value);

    $this->postJson('/admin/api/v1/countries', [
        'name' => 'Oman',
        'iso_code' => 'OM',
        'phone_code' => '+968',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Oman')
        ->assertJsonPath('data.iso_code', 'OM');

    $this->assertDatabaseHas('countries', ['iso_code' => 'OM']);
});

it('forbids a support user from creating a country', function (): void {
    actingAsGeoRole($this, PlatformRole::Support->value);

    $this->postJson('/admin/api/v1/countries', [
        'name' => 'Oman',
        'iso_code' => 'OM',
    ])->assertForbidden();
});

it('validates required country fields', function (): void {
    actingAsGeoRole($this, PlatformRole::SuperAdmin->value);

    $this->postJson('/admin/api/v1/countries', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'iso_code']);
});

it('rejects duplicate iso codes', function (): void {
    actingAsGeoRole($this, PlatformRole::SuperAdmin->value);
    Country::factory()->create(['iso_code' => 'OM']);

    $this->postJson('/admin/api/v1/countries', ['name' => 'Oman2', 'iso_code' => 'OM'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['iso_code']);
});

it('updates a country', function (): void {
    actingAsGeoRole($this, PlatformRole::SuperAdmin->value);
    $country = Country::factory()->create(['name' => 'Oman', 'iso_code' => 'OM']);

    $this->patchJson("/admin/api/v1/countries/{$country->id}", ['name' => 'Sultanate of Oman'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Sultanate of Oman');

    $this->assertDatabaseHas('countries', ['id' => $country->id, 'name' => 'Sultanate of Oman']);
});

it('refuses to delete a country that still has regions', function (): void {
    actingAsGeoRole($this, PlatformRole::SuperAdmin->value);
    $country = Country::factory()->create();
    Region::factory()->create(['country_id' => $country->id]);

    $this->deleteJson("/admin/api/v1/countries/{$country->id}")->assertStatus(409);

    $this->assertDatabaseHas('countries', ['id' => $country->id]);
});

it('deletes an empty country', function (): void {
    actingAsGeoRole($this, PlatformRole::SuperAdmin->value);
    $country = Country::factory()->create();

    $this->deleteJson("/admin/api/v1/countries/{$country->id}")->assertNoContent();

    $this->assertDatabaseMissing('countries', ['id' => $country->id]);
});

it('filters regions by country', function (): void {
    actingAsGeoRole($this, PlatformRole::Support->value);
    $a = Country::factory()->create();
    $b = Country::factory()->create();
    Region::factory()->create(['country_id' => $a->id, 'name' => 'Muscat']);
    Region::factory()->create(['country_id' => $b->id, 'name' => 'Dubai']);

    $response = $this->getJson("/admin/api/v1/regions?country_id={$a->id}")->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Muscat');
});

it('creates a region under a country', function (): void {
    actingAsGeoRole($this, PlatformRole::SuperAdmin->value);
    $country = Country::factory()->create();

    $this->postJson('/admin/api/v1/regions', [
        'country_id' => $country->id,
        'name' => 'Muscat',
        'type' => 'governorate',
    ])->assertCreated()->assertJsonPath('data.name', 'Muscat');

    $this->assertDatabaseHas('regions', ['country_id' => $country->id, 'name' => 'Muscat']);
});

it('filters districts by region', function (): void {
    actingAsGeoRole($this, PlatformRole::Support->value);
    $r1 = Region::factory()->create();
    $r2 = Region::factory()->create();
    District::factory()->create(['region_id' => $r1->id, 'name' => 'Al Khuwair']);
    District::factory()->create(['region_id' => $r2->id, 'name' => 'Downtown']);

    $response = $this->getJson("/admin/api/v1/districts?region_id={$r1->id}")->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Al Khuwair');
});

it('creates a district under a region', function (): void {
    actingAsGeoRole($this, PlatformRole::SuperAdmin->value);
    $region = Region::factory()->create();

    $this->postJson('/admin/api/v1/districts', [
        'region_id' => $region->id,
        'name' => 'Al Khuwair',
    ])->assertCreated();

    $this->assertDatabaseHas('districts', ['region_id' => $region->id, 'name' => 'Al Khuwair']);
});

it('filters cities by district', function (): void {
    actingAsGeoRole($this, PlatformRole::Support->value);
    $region = Region::factory()->create();
    $d1 = District::factory()->create(['region_id' => $region->id]);
    $d2 = District::factory()->create(['region_id' => $region->id]);
    City::factory()->create(['region_id' => $region->id, 'district_id' => $d1->id, 'name' => 'Bawshar']);
    City::factory()->create(['region_id' => $region->id, 'district_id' => $d2->id, 'name' => 'Ruwi']);

    $response = $this->getJson("/admin/api/v1/cities?district_id={$d1->id}")->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Bawshar');
});

it('creates a city under a region', function (): void {
    actingAsGeoRole($this, PlatformRole::SuperAdmin->value);
    $region = Region::factory()->create();

    $this->postJson('/admin/api/v1/cities', [
        'region_id' => $region->id,
        'name' => 'Bawshar',
    ])->assertCreated();

    $this->assertDatabaseHas('cities', ['region_id' => $region->id, 'name' => 'Bawshar']);
});
