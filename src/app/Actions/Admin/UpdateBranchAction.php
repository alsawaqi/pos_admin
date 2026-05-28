<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\UpdateBranchData;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

final readonly class UpdateBranchAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Branch $branch, UpdateBranchData $data, ?User $actor = null): Branch
    {
        return DB::transaction(function () use ($branch, $data, $actor): Branch {
            $before = $branch->only([
                'name', 'name_ar', 'code', 'manager_name', 'phone', 'email', 'address',
                'latitude', 'longitude', 'geofence_radius_m',
                'opening_hours_json', 'default_order_type', 'status',
            ]);

            $branch->fill($this->resolved([
                'name' => $data->name,
                'name_ar' => $data->nameAr,
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
                'geofence_radius_m' => $data->geofenceRadiusM,
                'opening_hours_json' => $data->openingHoursJson,
                'default_order_type' => $data->defaultOrderType,
                'status' => $data->status,
                'settings' => $data->settings,
            ]));

            if ($branch->isDirty()) {
                $branch->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'branch.updated',
                    actorUserId: $actor?->id,
                    companyId: $branch->company_id,
                    branchId: $branch->id,
                    auditableType: Branch::class,
                    auditableId: $branch->id,
                    oldValues: $before,
                    newValues: $branch->only(array_keys($before)),
                ));
            }

            return $branch->refresh();
        });
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
