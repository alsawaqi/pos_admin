<?php

declare(strict_types=1);

/*
 * LAUNCH-P1 P1-17 — branch radius 500–2000 m, no silently-saved default
 * Muscat pin, real opening hours.
 */

require_once __DIR__.'/../../Support/launch-p1-helpers.php';

use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function p1BranchPayload(Company $company, array $overrides = []): array
{
    return array_merge([
        'company_id' => $company->id,
        'name' => 'Qurum Branch',
        'latitude' => 23.6143,
        'longitude' => 58.4752,
        'geofence_radius_m' => 500,
    ], $overrides);
}

it('refuses a geofence radius below the 500 m blueprint minimum', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    $this->postJson('/admin/api/v1/branches', p1BranchPayload($company, ['geofence_radius_m' => 100]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['geofence_radius_m']);

    $this->postJson('/admin/api/v1/branches', p1BranchPayload($company, ['geofence_radius_m' => 2001]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['geofence_radius_m']);

    $this->postJson('/admin/api/v1/branches', p1BranchPayload($company, ['geofence_radius_m' => 2000]))
        ->assertCreated();
});

it('refuses a radius below 500 m on edit as well', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $branch = Branch::factory()->create(['geofence_radius_m' => 600]);

    $this->patchJson("/admin/api/v1/branches/{$branch->uuid}", ['geofence_radius_m' => 300])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['geofence_radius_m']);
});

it('does not save the untouched default Muscat map pin as a branch location', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    $this->postJson('/admin/api/v1/branches', p1BranchPayload($company, [
        'latitude' => 23.5859,
        'longitude' => 58.4059,
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['latitude']);

    expect(Branch::query()->count())->toBe(0);
});

it('requires a location on create', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    $payload = p1BranchPayload($company);
    unset($payload['latitude'], $payload['longitude']);

    $this->postJson('/admin/api/v1/branches', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['latitude', 'longitude']);
});

it('refuses impossible opening times', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    $this->postJson('/admin/api/v1/branches', p1BranchPayload($company, [
        'opening_hours_json' => [
            'mon' => ['open' => '29:00', 'close' => '22:00', 'closed' => false],
        ],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['opening_hours_json.mon.open']);
});

it('refuses a day whose closing time equals its opening time unless the day is closed', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    $this->postJson('/admin/api/v1/branches', p1BranchPayload($company, [
        'opening_hours_json' => [
            'tue' => ['open' => '09:00', 'close' => '09:00', 'closed' => false],
        ],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['opening_hours_json.tue.close']);

    // The same times are fine on a day marked closed.
    $this->postJson('/admin/api/v1/branches', p1BranchPayload($company, [
        'opening_hours_json' => [
            'tue' => ['open' => '09:00', 'close' => '09:00', 'closed' => true],
            'wed' => ['open' => '08:30', 'close' => '23:30', 'closed' => false],
        ],
    ]))
        ->assertCreated();
});

it('accepts a day that passes midnight (owner follow-up: 18:00-01:00 is valid)', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    $this->postJson('/admin/api/v1/branches', p1BranchPayload($company, [
        'opening_hours_json' => [
            'thu' => ['open' => '18:00', 'close' => '01:00', 'closed' => false],
            'fri' => ['open' => '16:30', 'close' => '00:00', 'closed' => false],
        ],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.opening_hours_json.thu.close', '01:00');

    // Invalid clock times are still refused on an overnight day.
    $this->postJson('/admin/api/v1/branches', p1BranchPayload($company, [
        'opening_hours_json' => [
            'sat' => ['open' => '18:00', 'close' => '25:00', 'closed' => false],
        ],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['opening_hours_json.sat.close']);
});

it('refuses opening hours on edit when close equals open', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $branch = Branch::factory()->create(['geofence_radius_m' => 500]);

    $this->patchJson("/admin/api/v1/branches/{$branch->uuid}", [
        'opening_hours_json' => ['fri' => ['open' => '14:00', 'close' => '14:00', 'closed' => false]],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['opening_hours_json.fri.close']);
});
