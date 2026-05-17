<?php

declare(strict_types=1);

use App\Actions\Admin\CreateCompanyAction;
use App\Data\Admin\CompanyActivitySelectionData;
use App\Data\Admin\CompanyComplianceData;
use App\Data\Admin\CompanyContactData;
use App\Data\Admin\CreateCompanyData;
use App\Data\Admin\OwnerProfileData;
use App\Enums\CompanyStatus;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\BusinessActivity;
use App\Models\Company;
use App\Models\CompanyStatusHistory;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\LaravelData\DataCollection;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

it('creates a company with full Oman compliance fields, activities, status history and audit log', function (): void {
    /** @var User $actor */
    $actor = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $actor->assignRole(PlatformRole::SuperAdmin->value);

    $fnb = BusinessActivity::factory()->create(['code' => 'TEST-FNB']);
    $retail = BusinessActivity::factory()->create(['code' => 'TEST-RTL']);

    $data = new CreateCompanyData(
        name: 'Qahwa House',
        nameAr: 'بيت القهوة',
        legalName: 'Qahwa House LLC',
        legalNameAr: 'بيت القهوة ش.م.م',
        compliance: new CompanyComplianceData(
            crNumber: '1234567',
            crIssueDate: '2024-01-15',
            crExpiryDate: '2027-01-14',
            establishmentDate: '2024-01-10',
            taxNumber: 'OMTX-100',
            vatNumber: 'OM5500000001',
            vatRegisteredAt: '2024-02-01',
            chamberOfCommerceNumber: 'CH-99',
            municipalityLicenseNumber: 'MUN-77',
        ),
        contact: new CompanyContactData(
            name: 'Salim Al-Harthy',
            phone: '+96891234567',
            email: 'contact@qahwa.test',
        ),
        owner: new OwnerProfileData(
            fullNameEn: 'Salim Al-Harthy',
            fullNameAr: 'سالم الحارثي',
            civilId: '12345678',
            nationality: 'OM',
            phone: '+96898765432',
            email: 'owner@qahwa.test',
        ),
        activities: new DataCollection(CompanyActivitySelectionData::class, [
            ['businessActivityId' => $fnb->id, 'isPrimary' => true],
            ['businessActivityId' => $retail->id, 'isPrimary' => false],
        ]),
        defaultCurrency: 'OMR',
        defaultLocale: 'en',
        status: CompanyStatus::Onboarding,
    );

    $company = app(CreateCompanyAction::class)->handle($data, $actor);

    expect($company->name)->toBe('Qahwa House')
        ->and($company->name_ar)->toBe('بيت القهوة')
        ->and($company->cr_number)->toBe('1234567')
        ->and($company->vat_number)->toBe('OM5500000001')
        ->and($company->owner_civil_id)->toBe('12345678')
        ->and($company->owner_nationality)->toBe('OM')
        ->and($company->onboarded_by_user_id)->toBe($actor->id)
        ->and($company->default_currency)->toBe('OMR')
        ->and($company->activities)->toHaveCount(2);

    $primary = $company->activities->firstWhere('id', $fnb->id);
    expect((bool) $primary?->pivot->is_primary)->toBeTrue();

    $this->assertDatabaseHas(CompanyStatusHistory::class, [
        'company_id' => $company->id,
        'from_status' => null,
        'to_status' => CompanyStatus::Onboarding->value,
        'changed_by_user_id' => $actor->id,
    ]);

    $this->assertDatabaseHas(AuditLog::class, [
        'event' => 'company.created',
        'company_id' => $company->id,
        'actor_user_id' => $actor->id,
    ]);
});

it('rolls back the entire creation when activities reference a missing id', function (): void {
    $real = BusinessActivity::factory()->create();

    $countBefore = Company::query()->count();

    $data = new CreateCompanyData(
        name: 'Broken Co',
        nameAr: null,
        legalName: null,
        legalNameAr: null,
        compliance: new CompanyComplianceData(crNumber: '9999999'),
        contact: new CompanyContactData,
        owner: new OwnerProfileData(fullNameEn: 'Owner Name'),
        activities: new DataCollection(CompanyActivitySelectionData::class, [
            ['businessActivityId' => $real->id, 'isPrimary' => false],
            ['businessActivityId' => 999_999, 'isPrimary' => false],
        ]),
    );

    expect(fn () => app(CreateCompanyAction::class)->handle($data))
        ->toThrow(InvalidArgumentException::class);

    expect(Company::query()->count())->toBe($countBefore);
});
