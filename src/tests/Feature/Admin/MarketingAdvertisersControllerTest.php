<?php

declare(strict_types=1);

/**
 * Feature tests for admin-driven advertiser onboarding
 * (/admin/api/v1/marketing/advertisers). Covers create (+ password hashing),
 * merchant linkage + its validation, duplicate-email rejection, the permission
 * gate, suspend-via-update, password reset, and list filtering.
 */

use App\Enums\PlatformRole;
use App\Models\Advertiser;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

/** Helper — log in as a platform admin with the given role. */
function actingAsMarketingRole(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

/** @return array<string, mixed> */
function advertiserPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jane Doe',
        'brand_name' => 'Acme Ads',
        'email' => 'jane@acme.test',
        'password' => 'secret123',
        'phone' => '+966500000000',
    ], $overrides);
}

/** Payload for the advertising-company onboarding wizard. */
function advertiserCompanyPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Bright Coffee Co',
        'compliance' => ['cr_number' => 'CR-ADV-1001'],
        'contact' => ['name' => 'Sara', 'phone' => '+96890000000', 'email' => 'sara@bright.test'],
        'owners' => [[
            'full_name_en' => 'Sara Said',
            'is_primary' => true,
        ]],
        'account' => [
            'email' => 'ads@bright.test',
            'password' => 'secret123',
            'brand_name' => 'Bright',
        ],
    ], $overrides);
}

it('creates an advertiser with a hashed password', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);

    $this->postJson('/admin/api/v1/marketing/advertisers', advertiserPayload())
        ->assertCreated()
        ->assertJsonPath('data.email', 'jane@acme.test')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.is_merchant', false);

    $advertiser = Advertiser::firstWhere('email', 'jane@acme.test');
    expect($advertiser)->not->toBeNull();
    expect(Hash::check('secret123', $advertiser->password))->toBeTrue();
});

it('links a merchant advertiser to a company', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->postJson('/admin/api/v1/marketing/advertisers', advertiserPayload([
        'email' => 'merchant@acme.test',
        'is_merchant' => true,
        'company_id' => $company->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.is_merchant', true)
        ->assertJsonPath('data.company_id', $company->id)
        ->assertJsonPath('data.company.id', $company->id);
});

it('requires a company when is_merchant is true', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);

    $this->postJson('/admin/api/v1/marketing/advertisers', advertiserPayload([
        'email' => 'nolink@acme.test',
        'is_merchant' => true,
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['company_id']);
});

it('rejects a duplicate email', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    Advertiser::factory()->create(['email' => 'taken@acme.test']);

    $this->postJson('/admin/api/v1/marketing/advertisers', advertiserPayload([
        'email' => 'taken@acme.test',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('forbids a Support role from creating an advertiser', function (): void {
    actingAsMarketingRole($this, PlatformRole::Support->value);

    $this->postJson('/admin/api/v1/marketing/advertisers', advertiserPayload())
        ->assertForbidden();

    $this->assertDatabaseMissing('advertisers', ['email' => 'jane@acme.test']);
});

it('suspends an advertiser via update and clears the company link when un-merchanted', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();
    $advertiser = Advertiser::factory()->create([
        'status' => 'active',
        'is_merchant' => true,
        'company_id' => $company->id,
    ]);

    $this->patchJson("/admin/api/v1/marketing/advertisers/{$advertiser->id}", [
        'status' => 'suspended',
        'is_merchant' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended')
        ->assertJsonPath('data.is_merchant', false)
        ->assertJsonPath('data.company_id', null);

    $this->assertDatabaseHas('advertisers', [
        'id' => $advertiser->id,
        'status' => 'suspended',
        'company_id' => null,
    ]);
});

it('resets an advertiser password and returns the new one once', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    $advertiser = Advertiser::factory()->create();
    $oldHash = $advertiser->password;

    $response = $this->postJson("/admin/api/v1/marketing/advertisers/{$advertiser->id}/reset-password")
        ->assertOk();

    $plain = $response->json('data.password');
    expect($plain)->toBeString()->not->toBeEmpty();

    $advertiser->refresh();
    expect($advertiser->password)->not->toBe($oldHash);
    expect(Hash::check($plain, $advertiser->password))->toBeTrue();
});

it('lists advertisers and filters by search', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    Advertiser::factory()->create(['brand_name' => 'Lulu Mart']);
    Advertiser::factory()->create(['brand_name' => 'Other Co']);

    $response = $this->getJson('/admin/api/v1/marketing/advertisers?search=Lulu')->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.brand_name'))->toBe('Lulu Mart');
});

it('forbids a Support role from listing advertisers', function (): void {
    actingAsMarketingRole($this, PlatformRole::Support->value);

    $this->getJson('/admin/api/v1/marketing/advertisers')->assertForbidden();
});

// --- Advertising-company onboarding wizard -----------------------------------

it('onboards a new advertising-only company with a portal login', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);

    $this->postJson('/admin/api/v1/marketing/advertisers/with-company', advertiserCompanyPayload())
        ->assertCreated()
        ->assertJsonPath('data.email', 'ads@bright.test')
        ->assertJsonPath('data.is_merchant', false)
        ->assertJsonPath('data.brand_name', 'Bright')
        ->assertJsonPath('data.company.name', 'Bright Coffee Co');

    $company = Company::firstWhere('cr_number', 'CR-ADV-1001');
    expect($company)->not->toBeNull();
    expect($company->is_advertiser_only)->toBeTrue();

    $advertiser = Advertiser::firstWhere('email', 'ads@bright.test');
    expect($advertiser->company_id)->toBe($company->id);
    expect($advertiser->is_merchant)->toBeFalse();
    expect(Hash::check('secret123', $advertiser->password))->toBeTrue();

    $this->assertDatabaseHas('pos_company_owners', [
        'company_id' => $company->id,
        'full_name_en' => 'Sara Said',
        'is_primary' => true,
    ]);
});

it('falls back to the company name + primary owner when the login leaves them blank', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);

    $this->postJson('/admin/api/v1/marketing/advertisers/with-company', advertiserCompanyPayload([
        // No brand, no login contact, and no company contact name → the brand
        // falls back to the trade name and the contact to the primary owner.
        'contact' => ['name' => null],
        'account' => ['brand_name' => null, 'contact_name' => null],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.brand_name', 'Bright Coffee Co')
        ->assertJsonPath('data.name', 'Sara Said');
});

it('rejects an advertising company whose login email is already taken', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    Advertiser::factory()->create(['email' => 'ads@bright.test']);

    $this->postJson('/admin/api/v1/marketing/advertisers/with-company', advertiserCompanyPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['account.email']);
});

it('requires exactly one primary owner for an advertising company', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);

    $this->postJson('/admin/api/v1/marketing/advertisers/with-company', advertiserCompanyPayload([
        'owners' => [
            ['full_name_en' => 'A', 'is_primary' => false],
            ['full_name_en' => 'B', 'is_primary' => false],
        ],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['owners']);
});

it('forbids a Support role from onboarding an advertising company', function (): void {
    actingAsMarketingRole($this, PlatformRole::Support->value);

    $this->postJson('/admin/api/v1/marketing/advertisers/with-company', advertiserCompanyPayload())
        ->assertForbidden();

    $this->assertDatabaseMissing('advertisers', ['email' => 'ads@bright.test']);
});

it('excludes advertising-only companies from the merchants list', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    Company::factory()->create(['name' => 'Real Merchant']);
    Company::factory()->create(['name' => 'Ad Co', 'is_advertiser_only' => true]);

    $response = $this->getJson('/admin/api/v1/merchants')->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('Real Merchant');
    expect($names)->not->toContain('Ad Co');
});

it('keeps the advertising-only company link on a profile edit', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create(['is_advertiser_only' => true]);
    $advertiser = Advertiser::factory()->create([
        'is_merchant' => false,
        'company_id' => $company->id,
    ]);

    $this->patchJson("/admin/api/v1/marketing/advertisers/{$advertiser->id}", [
        'name' => 'New Contact',
    ])
        ->assertOk()
        ->assertJsonPath('data.company_id', $company->id);

    $this->assertDatabaseHas('advertisers', [
        'id' => $advertiser->id,
        'company_id' => $company->id,
    ]);
});

it('keeps the link even when an advertising-only advertiser is re-saved as non-merchant', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create(['is_advertiser_only' => true]);
    $advertiser = Advertiser::factory()->create([
        'is_merchant' => false,
        'company_id' => $company->id,
    ]);

    $this->patchJson("/admin/api/v1/marketing/advertisers/{$advertiser->id}", [
        'is_merchant' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.company_id', $company->id);
});

// --- Advertiser detail page (show + company editing) -------------------------

it('shows advertiser detail with the linked company and content stats', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create(['is_advertiser_only' => true, 'name' => 'Bright Co']);
    $advertiser = Advertiser::factory()->create(['company_id' => $company->id, 'is_merchant' => false]);
    ContentAsset::factory()->status('pending')->create(['advertiser_id' => $advertiser->id]);
    ContentAsset::factory()->status('approved')->create(['advertiser_id' => $advertiser->id]);

    $this->getJson("/admin/api/v1/marketing/advertisers/{$advertiser->id}")
        ->assertOk()
        ->assertJsonPath('data.company.is_advertiser_only', true)
        ->assertJsonPath('data.company.name', 'Bright Co')
        ->assertJsonPath('data.content_stats.total', 2)
        ->assertJsonPath('data.content_stats.pending', 1)
        ->assertJsonPath('data.content_stats.approved', 1);
});

it('updates the advertising-only company commercial registration', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create(['is_advertiser_only' => true, 'cr_number' => 'CR-OLD']);
    $advertiser = Advertiser::factory()->create(['company_id' => $company->id]);

    $this->patchJson("/admin/api/v1/marketing/advertisers/{$advertiser->id}/company", [
        'name' => 'New Trade Name',
        'compliance' => ['cr_number' => 'CR-NEW-123'],
    ])
        ->assertOk()
        ->assertJsonPath('data.company.compliance.cr_number', 'CR-NEW-123');

    $this->assertDatabaseHas('pos_companies', [
        'id' => $company->id,
        'name' => 'New Trade Name',
        'cr_number' => 'CR-NEW-123',
    ]);
});

it('refuses to edit a real merchant company from the advertiser page', function (): void {
    actingAsMarketingRole($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create(['is_advertiser_only' => false, 'name' => 'Real Merchant']);
    $advertiser = Advertiser::factory()->create(['company_id' => $company->id, 'is_merchant' => true]);

    $this->patchJson("/admin/api/v1/marketing/advertisers/{$advertiser->id}/company", [
        'name' => 'Hacked',
        'compliance' => ['cr_number' => 'CR-X'],
    ])->assertStatus(422);

    $this->assertDatabaseHas('pos_companies', ['id' => $company->id, 'name' => 'Real Merchant']);
});

it('forbids a Support role from viewing advertiser detail', function (): void {
    actingAsMarketingRole($this, PlatformRole::Support->value);
    $advertiser = Advertiser::factory()->create();

    $this->getJson("/admin/api/v1/marketing/advertisers/{$advertiser->id}")->assertForbidden();
});
