<?php

declare(strict_types=1);

/*
 * LAUNCH-P1 P1-18 (business activity required, exactly one primary),
 * P1-19 (Active needs a verified CR certificate + owner ID card) and the
 * low finding "an invalid status change returns a 500".
 */

require_once __DIR__.'/../../Support/launch-p1-helpers.php';

use App\Enums\CompanyStatus;
use App\Enums\DocumentType;
use App\Enums\PlatformRole;
use App\Models\BusinessActivity;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyStatusHistory;
use App\Support\Compliance\MerchantActivationRequirements;
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
function p1MerchantPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Phase One Cafe',
        'compliance' => ['cr_number' => (string) random_int(1_000_000, 9_999_999)],
        'contact' => ['name' => 'Salim', 'phone' => '+96891234567', 'email' => 'salim@cafe.test'],
        'owners' => [['full_name_en' => 'Salim Al-Harthy', 'is_primary' => true]],
    ], $overrides);
}

it('refuses a merchant without any business activity', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);

    $this->postJson('/admin/api/v1/merchants', p1MerchantPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['activities']);

    expect(Company::query()->count())->toBe(0);
});

it('answers two primary business activities with a 422, not a 500', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    [$a, $b] = BusinessActivity::factory()->count(2)->create();

    $this->postJson('/admin/api/v1/merchants', p1MerchantPayload([
        'activities' => [
            ['business_activity_id' => $a->id, 'is_primary' => true],
            ['business_activity_id' => $b->id, 'is_primary' => true],
        ],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['activities']);
});

it('requires one activity to be marked primary', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $a = BusinessActivity::factory()->create();

    $this->postJson('/admin/api/v1/merchants', p1MerchantPayload([
        'activities' => [['business_activity_id' => $a->id, 'is_primary' => false]],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['activities']);
});

it('creates a merchant with exactly one primary activity in onboarding', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    [$a, $b] = BusinessActivity::factory()->count(2)->create();

    $this->postJson('/admin/api/v1/merchants', p1MerchantPayload([
        'activities' => [
            ['business_activity_id' => $a->id, 'is_primary' => true],
            ['business_activity_id' => $b->id, 'is_primary' => false],
        ],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'onboarding')
        ->assertJsonCount(2, 'data.activities');
});

it('does not let a new merchant skip onboarding by posting status active', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $a = BusinessActivity::factory()->create();

    $this->postJson('/admin/api/v1/merchants', p1MerchantPayload([
        'activities' => [['business_activity_id' => $a->id, 'is_primary' => true]],
        'status' => 'active',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('answers two primaries on the edit-activities endpoint with a 422', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();
    [$a, $b] = BusinessActivity::factory()->count(2)->create();

    $this->putJson("/admin/api/v1/merchants/{$company->uuid}/activities", [
        'activities' => [
            ['business_activity_id' => $a->id, 'is_primary' => true],
            ['business_activity_id' => $b->id, 'is_primary' => true],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['activities']);
});

it('refuses Active without a verified CR certificate and owner ID card, listing what is missing', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $response = $this->postJson("/admin/api/v1/merchants/{$company->uuid}/status", ['target_status' => 'active'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'required_documents_missing')
        ->assertJsonPath('missing.0.type', 'cr_certificate')
        ->assertJsonPath('missing.0.status', 'missing')
        ->assertJsonPath('missing.1.type', 'owner_id_card')
        ->assertJsonPath('missing.1.status', 'missing');

    expect($response->json('message'))->toContain('CR certificate')->toContain('Owner ID card');
    expect($company->fresh()->status)->toBe(CompanyStatus::Onboarding);
    $this->assertDatabaseMissing('pos_company_status_history', ['company_id' => $company->id, 'to_status' => 'active']);
});

it('does not count an unverified or expired document towards activation', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();
    CompanyDocument::factory()->for($company)->create(['document_type' => DocumentType::CrCertificate]); // pending
    CompanyDocument::factory()->for($company)->verified()->create([
        'document_type' => DocumentType::OwnerIdCard,
        'expires_at' => now()->subDay(),
    ]);

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/status", ['target_status' => 'active'])
        ->assertStatus(422)
        ->assertJsonPath('missing.0.type', 'cr_certificate')
        ->assertJsonPath('missing.0.status', 'pending')
        ->assertJsonPath('missing.1.type', 'owner_id_card')
        ->assertJsonPath('missing.1.status', 'expired');
});

it('activates a merchant once both required documents are verified', function (): void {
    $admin = p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();
    CompanyDocument::factory()->for($company)->verified()->create(['document_type' => DocumentType::CrCertificate]);
    CompanyDocument::factory()->for($company)->verified()->create(['document_type' => DocumentType::OwnerIdCard]);

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}")
        ->assertOk()
        ->assertJsonPath('data.activation_requirements.0.satisfied', true)
        ->assertJsonPath('data.activation_requirements.1.satisfied', true);

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/status", ['target_status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('pos_company_status_history', [
        'company_id' => $company->id,
        'to_status' => 'active',
        'changed_by_user_id' => $admin->id,
    ]);
});

it('lifts a suspension without asking for the documents again', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->suspended()->create(); // was live, no documents on file

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}")
        ->assertOk()
        ->assertJsonPath('data.activation_requires_documents', false);

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/status", ['target_status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('recognises a legacy merchant that went live before activated_at existed', function (): void {
    $admin = p1ActingAs($this, PlatformRole::SuperAdmin->value);
    // Created straight into active (pre-P1 wizard), later suspended:
    // no activated_at, only the status history shows it was live.
    $company = Company::factory()->create([
        'status' => CompanyStatus::Suspended,
        'activated_at' => null,
        'suspended_at' => now()->subDay(),
    ]);
    CompanyStatusHistory::query()->create([
        'company_id' => $company->id,
        'from_status' => null,
        'to_status' => CompanyStatus::Active,
        'changed_by_user_id' => $admin->id,
        'reason' => 'Initial onboarding',
    ]);

    expect(MerchantActivationRequirements::wasActiveBefore($company))->toBeTrue();

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/status", ['target_status' => 'active'])
        ->assertOk();
});

it('keeps requiring the documents for a first activation', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create(['status' => CompanyStatus::Onboarding, 'activated_at' => null]);

    expect(MerchantActivationRequirements::wasActiveBefore($company))->toBeFalse();

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}")
        ->assertOk()
        ->assertJsonPath('data.activation_requires_documents', true);
    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/status", ['target_status' => 'active'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'required_documents_missing');
});

it('shows the required-document checklist on the merchant page payload', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();
    CompanyDocument::factory()->for($company)->rejected()->create(['document_type' => DocumentType::OwnerIdCard]);

    $this->getJson("/admin/api/v1/merchants/{$company->uuid}")
        ->assertOk()
        ->assertJsonPath('data.activation_requirements.0.label', 'CR certificate')
        ->assertJsonPath('data.activation_requirements.0.status', 'missing')
        ->assertJsonPath('data.activation_requirements.1.label', 'Owner ID card')
        ->assertJsonPath('data.activation_requirements.1.status', 'rejected')
        ->assertJsonPath('data.allowed_transitions', ['active', 'inactive']);
});

it('answers an impossible status change with a 422 instead of a 500', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create(['status' => CompanyStatus::Inactive]);

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/status", ['target_status' => 'active'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_status_transition');
});
