<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\UpdateCompanyData;
use App\Data\Security\AuditLogData;
use App\Models\Company;
use App\Models\CompanyOwner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Applies a PATCH to an existing {@see Company}. Every section of
 * the DTO is optional — only fields the caller actually sent get
 * written. When the caller sends an `owners` array, it REPLACES the
 * full set (sync semantics) so deleting an owner is achieved simply
 * by omitting them on the next update.
 */
final readonly class UpdateCompanyAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Company $company, UpdateCompanyData $data, ?User $actor = null): Company
    {
        return DB::transaction(function () use ($company, $data, $actor): Company {
            // Snapshot scalar fields BEFORE we touch the model so the
            // audit log can record old vs new values.
            $before = $company->only([
                'name', 'name_ar', 'legal_name', 'legal_name_ar', 'cr_number', 'vat_number',
                'contact_email', 'default_currency', 'default_locale',
            ]);

            // --- 1. Scalar attribute updates ----------------------------
            $attributes = $this->topLevelAttributes($data);
            $attributes = array_merge($attributes, $this->complianceAttributes($data));
            $attributes = array_merge($attributes, $this->contactAttributes($data));

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

            // --- 2. Owners sync (only when present in the payload) ------
            // The Optional check is what distinguishes "caller didn't
            // send owners, leave them alone" from "caller sent an
            // empty list, wipe everything". Today we only do the
            // former by validation, but the guard keeps both safe.
            if (! $data->owners instanceof Optional) {
                $this->syncOwners($company, $data, $actor);
            }

            return $company->refresh();
        });
    }

    /**
     * Wipes existing owner rows and recreates them from the payload.
     *
     * Simpler and safer than diffing — owner rows are tiny, this
     * runs inside the same DB transaction, and the audit log
     * captures the before/after snapshot of every owner full_name.
     *
     * The transaction in handle() guarantees this is atomic: either
     * the company AND its full new owner set commit together, or
     * neither does.
     */
    private function syncOwners(Company $company, UpdateCompanyData $data, ?User $actor): void
    {
        $before = $company->owners()->get()
            ->map(fn (CompanyOwner $o): array => $o->only(['full_name_en', 'email', 'is_primary']))
            ->all();

        $company->owners()->delete();

        /** @var \Spatie\LaravelData\DataCollection<int, \App\Data\Admin\CompanyOwnerData> $owners */
        $owners = $data->owners;
        foreach ($owners as $ownerData) {
            CompanyOwner::query()->create([
                'company_id' => $company->id,
                'full_name_en' => $ownerData->fullNameEn,
                'full_name_ar' => $ownerData->fullNameAr,
                'civil_id' => $ownerData->civilId,
                'nationality' => $ownerData->nationality,
                'phone' => $ownerData->phone,
                'email' => $ownerData->email,
                'is_primary' => $ownerData->isPrimary,
                'ownership_percentage' => $ownerData->ownershipPercentage,
            ]);
        }

        $after = $company->owners()->get()
            ->map(fn (CompanyOwner $o): array => $o->only(['full_name_en', 'email', 'is_primary']))
            ->all();

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'company.owners.synced',
            actorUserId: $actor?->id,
            companyId: $company->id,
            auditableType: Company::class,
            auditableId: $company->id,
            oldValues: ['owners' => $before],
            newValues: ['owners' => $after],
        ));
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
     * Drops any DTO fields that came back as Optional — i.e. ones
     * the caller didn't include in the payload — so they don't
     * end up overwriting model attributes with empty values.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function resolved(array $attributes): array
    {
        return array_filter($attributes, static fn (mixed $value): bool => ! $value instanceof Optional);
    }
}
