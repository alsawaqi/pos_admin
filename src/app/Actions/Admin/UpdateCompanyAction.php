<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\UpdateCompanyData;
use App\Data\Security\AuditLogData;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

final readonly class UpdateCompanyAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Company $company, UpdateCompanyData $data, ?User $actor = null): Company
    {
        return DB::transaction(function () use ($company, $data, $actor): Company {
            $before = $company->only([
                'name', 'name_ar', 'legal_name', 'legal_name_ar', 'cr_number', 'vat_number',
                'contact_email', 'default_currency', 'default_locale',
            ]);

            $attributes = $this->topLevelAttributes($data);
            $attributes = array_merge($attributes, $this->complianceAttributes($data));
            $attributes = array_merge($attributes, $this->contactAttributes($data));
            $attributes = array_merge($attributes, $this->ownerAttributes($data));

            $company->fill($attributes);

            if ($company->isDirty()) {
                $company->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'company.updated',
                    actorUserId: $actor?->id,
                    companyId: $company->id,
                    auditableType: Company::class,
                    auditableId: $company->id,
                    oldValues: $before,
                    newValues: $company->only(array_keys($before)),
                ));
            }

            return $company->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function topLevelAttributes(UpdateCompanyData $data): array
    {
        return $this->resolved([
            'name' => $data->name,
            'name_ar' => $data->nameAr,
            'legal_name' => $data->legalName,
            'legal_name_ar' => $data->legalNameAr,
            'default_currency' => $data->defaultCurrency,
            'default_locale' => $data->defaultLocale,
            'settings' => $data->settings,
            'notes' => $data->notes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function complianceAttributes(UpdateCompanyData $data): array
    {
        if ($data->compliance instanceof Optional) {
            return [];
        }

        $compliance = $data->compliance;

        return [
            'cr_number' => $compliance->crNumber,
            'cr_issue_date' => $compliance->crIssueDate,
            'cr_expiry_date' => $compliance->crExpiryDate,
            'establishment_date' => $compliance->establishmentDate,
            'tax_number' => $compliance->taxNumber,
            'vat_number' => $compliance->vatNumber,
            'vat_registered_at' => $compliance->vatRegisteredAt,
            'chamber_of_commerce_number' => $compliance->chamberOfCommerceNumber,
            'municipality_license_number' => $compliance->municipalityLicenseNumber,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactAttributes(UpdateCompanyData $data): array
    {
        if ($data->contact instanceof Optional) {
            return [];
        }

        $contact = $data->contact;

        return [
            'contact_name' => $contact->name,
            'contact_phone' => $contact->phone,
            'contact_email' => $contact->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerAttributes(UpdateCompanyData $data): array
    {
        if ($data->owner instanceof Optional) {
            return [];
        }

        $owner = $data->owner;

        return [
            'owner_full_name_en' => $owner->fullNameEn,
            'owner_full_name_ar' => $owner->fullNameAr,
            'owner_civil_id' => $owner->civilId,
            'owner_nationality' => $owner->nationality,
            'owner_phone' => $owner->phone,
            'owner_email' => $owner->email,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function resolved(array $attributes): array
    {
        return array_filter($attributes, static fn (mixed $value): bool => ! $value instanceof Optional);
    }
}
