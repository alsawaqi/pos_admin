<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\CreateCompanyData;
use App\Data\Security\AuditLogData;
use App\Models\Company;
use App\Models\CompanyStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateCompanyAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private SyncCompanyActivitiesAction $syncActivities,
    ) {}

    public function handle(CreateCompanyData $data, ?User $actor = null): Company
    {
        return DB::transaction(function () use ($data, $actor): Company {
            $compliance = $data->compliance;
            $contact = $data->contact;
            $owner = $data->owner;

            /** @var Company $company */
            $company = Company::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $data->name,
                'name_ar' => $data->nameAr,
                'legal_name' => $data->legalName,
                'legal_name_ar' => $data->legalNameAr,

                'cr_number' => $compliance->crNumber,
                'cr_issue_date' => $compliance->crIssueDate,
                'cr_expiry_date' => $compliance->crExpiryDate,
                'establishment_date' => $compliance->establishmentDate,
                'tax_number' => $compliance->taxNumber,
                'vat_number' => $compliance->vatNumber,
                'vat_registered_at' => $compliance->vatRegisteredAt,
                'chamber_of_commerce_number' => $compliance->chamberOfCommerceNumber,
                'municipality_license_number' => $compliance->municipalityLicenseNumber,

                'contact_name' => $contact->name,
                'contact_phone' => $contact->phone,
                'contact_email' => $contact->email,

                'owner_full_name_en' => $owner->fullNameEn,
                'owner_full_name_ar' => $owner->fullNameAr,
                'owner_civil_id' => $owner->civilId,
                'owner_nationality' => $owner->nationality,
                'owner_phone' => $owner->phone,
                'owner_email' => $owner->email,

                'default_currency' => $data->defaultCurrency,
                'default_locale' => $data->defaultLocale,
                'onboarded_by_user_id' => $actor?->id,
                'status' => $data->status,
                'settings' => $data->settings,
                'notes' => $data->notes,
            ]);

            if ($data->activities !== null && $data->activities->count() > 0) {
                $this->syncActivities->handle($company, $data->activities->toCollection()->all(), $actor, recordAudit: false);
            }

            CompanyStatusHistory::query()->create([
                'company_id' => $company->id,
                'from_status' => null,
                'to_status' => $data->status,
                'changed_by_user_id' => $actor?->id,
                'reason' => 'Initial onboarding',
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'company.created',
                actorUserId: $actor?->id,
                companyId: $company->id,
                auditableType: Company::class,
                auditableId: $company->id,
                newValues: $company->only([
                    'uuid',
                    'name',
                    'cr_number',
                    'vat_number',
                    'contact_email',
                    'status',
                ]),
            ));

            return $company->fresh(['activities', 'statusHistory']) ?? $company;
        });
    }
}
