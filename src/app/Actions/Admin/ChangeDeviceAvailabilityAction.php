<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\DeviceAssignmentHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ChangeDeviceAvailabilityAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLog) {}

    public function handle(Device $device, string $operation, User $actor): Device
    {
        return DB::transaction(function () use ($device, $operation, $actor): Device {
            $device = Device::withTrashed()->lockForUpdate()->findOrFail($device->id);
            $before = $device->only(['status', 'deleted_at', 'bank_id', 'terminal_id']);
            $metadata = $device->metadata ?? [];

            if ($operation === 'restore') {
                if (! $device->trashed()) {
                    return $device;
                }
                // Recovery restores visibility only; it never silently enables POS access.
                $metadata['disabled_previous_status'] = $this->enrolledStatus($device)->value;
                $device->status = DeviceStatus::Inactive;
                $device->metadata = $metadata;
                $device->restore();
                if ($device->company_id !== null && $device->branch_id !== null
                    && ! $device->assignmentHistory()->whereNull('unassigned_at')->exists()) {
                    DeviceAssignmentHistory::query()->create([
                        'device_id' => $device->id,
                        'company_id' => $device->company_id,
                        'branch_id' => $device->branch_id,
                        'assigned_at' => now(),
                        'assigned_by_admin_id' => $actor->id,
                    ]);
                }
            } else {
                if ($device->trashed()) {
                    throw ValidationException::withMessages(['device' => 'Restore this archived device as disabled first.']);
                }
                switch ($operation) {
                    case 'disable':
                        if (in_array($device->status, [DeviceStatus::Inactive, DeviceStatus::Blocked], true)) {
                            return $device;
                        }
                        $metadata['disabled_previous_status'] = $device->status?->value;
                        $device->metadata = $metadata;
                        $device->status = DeviceStatus::Inactive;
                        break;
                    case 'enable':
                        if (! in_array($device->status, [DeviceStatus::Inactive, DeviceStatus::Blocked], true)) {
                            return $device;
                        }
                        if ($device->branch_id !== null) {
                            if ($device->device_type === DeviceType::PaymentStation && trim($device->terminal_id ?? '') === '') {
                                throw ValidationException::withMessages(['terminal_id' => 'Set a bank and terminal ID before enabling this payment station.']);
                            }
                            app(AssertDeviceSoftPosAssignment::class)->handle($device->device_type, $device->bank_id, $device->terminal_id);
                        }
                        $previous = DeviceStatus::tryFrom((string) ($metadata['disabled_previous_status'] ?? ''));
                        $device->status = $device->branch_id === null ? DeviceStatus::Registered
                            : ($previous === DeviceStatus::Assigned ? DeviceStatus::Assigned : $this->enrolledStatus($device));
                        unset($metadata['disabled_previous_status']);
                        $device->metadata = $metadata;
                        break;
                    case 'release-terminal':
                        if (! in_array($device->status, [DeviceStatus::Inactive, DeviceStatus::Blocked], true)) {
                            throw ValidationException::withMessages(['device' => 'Disable this device before releasing its bank terminal.']);
                        }
                        if ($device->bank_id === null && $device->terminal_id === null && $device->terminal_pin === null) {
                            return $device;
                        }
                        $device->bank_id = null;
                        $device->terminal_id = null;
                        $device->terminal_pin = null;
                        break;
                    default:
                        throw ValidationException::withMessages(['operation' => 'Unknown device action.']);
                }
                $device->save();
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: match ($operation) {
                    'disable' => 'device.disabled',
                    'enable' => 'device.enabled',
                    'restore' => 'device.restored_disabled',
                    default => 'device.terminal_released',
                },
                actorUserId: $actor->id,
                companyId: $device->company_id,
                branchId: $device->branch_id,
                auditableType: Device::class,
                auditableId: $device->id,
                oldValues: $before,
                newValues: $device->only(array_keys($before)),
            ));

            return $device->refresh();
        });
    }

    private function enrolledStatus(Device $device): DeviceStatus
    {
        if ($device->company_id === null || $device->branch_id === null) {
            return DeviceStatus::Registered;
        }

        return filled($device->device_token) || $device->tokens()->exists()
            ? DeviceStatus::Active : DeviceStatus::Assigned;
    }
}
