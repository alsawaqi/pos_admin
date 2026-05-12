<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\CreateBranchData;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateBranchAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(CreateBranchData $data, ?User $actor = null): Branch
    {
        return DB::transaction(function () use ($data, $actor): Branch {
            /** @var Company $company */
            $company = Company::query()->findOrFail($data->companyId);

            /** @var Branch $branch */
            $branch = Branch::query()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $company->id,
                'name' => $data->name,
                'code' => $data->code,
                'manager_name' => $data->managerName,
                'phone' => $data->phone,
                'email' => $data->email,
                'address' => $data->address,
                'country_id' => $data->countryId,
                'region_id' => $data->regionId,
                'district_id' => $data->districtId,
                'city_id' => $data->cityId,
                'latitude' => $data->latitude,
                'longitude' => $data->longitude,
                'status' => $data->status,
                'settings' => $data->settings,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'branch.created',
                actorUserId: $actor?->id,
                companyId: $company->id,
                branchId: $branch->id,
                auditableType: Branch::class,
                auditableId: $branch->id,
                newValues: $branch->only(['uuid', 'company_id', 'name', 'code', 'status']),
            ));

            return $branch;
        });
    }
}
