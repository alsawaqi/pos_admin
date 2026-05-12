<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\RegisterDeviceData;
use App\Data\Security\AuditLogData;
use App\Enums\DeviceStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RegisterDeviceAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(RegisterDeviceData $data, ?User $actor = null): Device
    {
        return DB::transaction(function () use ($data, $actor): Device {
            if ($data->branchId !== null && $data->companyId === null) {
                throw new InvalidArgumentException('A branch-assigned device must also include a company id.');
            }

            $branch = null;

            if ($data->branchId !== null) {
                /** @var Branch $branch */
                $branch = Branch::query()
                    ->whereKey($data->branchId)
                    ->where('company_id', $data->companyId)
                    ->firstOrFail();
            }

            $status = $branch instanceof Branch ? DeviceStatus::Assigned : DeviceStatus::Registered;

            /** @var Device $device */
            $device = Device::query()->create([
                'uuid' => (string) Str::uuid(),
                'serial_number' => $data->serialNumber,
                'name' => $data->name,
                'device_type' => $data->deviceType,
                'company_id' => $data->companyId,
                'branch_id' => $data->branchId,
                'registered_by_user_id' => $actor?->id,
                'assigned_by_user_id' => $branch instanceof Branch ? $actor?->id : null,
                'status' => $status,
                'assigned_at' => $branch instanceof Branch ? now() : null,
                'app_version' => $data->appVersion,
                'firmware_version' => $data->firmwareVersion,
                'metadata' => $data->metadata,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device.registered',
                actorUserId: $actor?->id,
                companyId: $data->companyId,
                branchId: $data->branchId,
                auditableType: Device::class,
                auditableId: $device->id,
                newValues: $device->only([
                    'uuid',
                    'serial_number',
                    'company_id',
                    'branch_id',
                    'status',
                    'assigned_at',
                ]),
            ));

            return $device;
        });
    }
}
