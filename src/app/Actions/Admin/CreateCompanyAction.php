<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\CreateCompanyData;
use App\Data\Security\AuditLogData;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateCompanyAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(CreateCompanyData $data, ?User $actor = null): Company
    {
        return DB::transaction(function () use ($data, $actor): Company {
            /** @var Company $company */
            $company = Company::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $data->name,
                'legal_name' => $data->legalName,
                'commercial_registration_number' => $data->commercialRegistrationNumber,
                'tax_number' => $data->taxNumber,
                'contact_name' => $data->contactName,
                'contact_phone' => $data->contactPhone,
                'contact_email' => $data->contactEmail,
                'status' => $data->status,
                'settings' => $data->settings,
                'notes' => $data->notes,
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
                    'legal_name',
                    'commercial_registration_number',
                    'contact_email',
                    'status',
                ]),
            ));

            return $company;
        });
    }
}
