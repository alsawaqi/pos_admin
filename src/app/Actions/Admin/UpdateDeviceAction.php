<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\UpdateDeviceData;
use App\Data\Security\AuditLogData;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\User;
use App\Support\DeviceSerial;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Edits a registered device's identity + catalogue + commission/organization
 * bindings. Partial: only the fields actually sent (non-Optional) are filled.
 * No-op when nothing changed (isDirty guard) — and only then is a
 * `device.updated` audit row written, capturing the before/after diff.
 *
 * LAUNCH-P1 P1-12: the serial number and the device type are what an
 * activation is locked to. Changing either one on a device revokes its
 * credential and its outstanding activation codes (it must be activated again,
 * on the right hardware with the right app) and writes a
 * `device.credentials_revoked` audit row.
 *
 * Mirrors {@see UpdateBranchAction}. Does NOT touch assignment (company/branch),
 * terminal_id/bank_id, or status — those have their own workflow actions.
 */
final readonly class UpdateDeviceAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Device $device, UpdateDeviceData $data, ?User $actor = null): Device
    {
        return DB::transaction(function () use ($device, $data, $actor): Device {
            // Lock like the other lifecycle actions: revocation below expects it.
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $before = $device->only([
                'serial_number', 'kiosk_id', 'name', 'label', 'make_id', 'model_id',
                'device_type', 'commission_profile_id', 'organization_id',
            ]);

            $device->fill($this->resolved([
                'serial_number' => is_string($data->serialNumber)
                    ? (DeviceSerial::normalize($data->serialNumber) ?? $data->serialNumber) : $data->serialNumber,
                'kiosk_id' => $data->kioskId,
                'name' => $data->name,
                'label' => $data->label,
                'make_id' => $data->makeId,
                'model_id' => $data->modelId,
                'device_type' => $data->deviceType,
                'commission_profile_id' => $data->commissionProfileId,
                'organization_id' => $data->organizationId,
            ]));

            if ($device->isDirty()) {
                if ($device->isDirty('device_type') && $device->branch_id !== null) {
                    app(AssertDeviceSoftPosAssignment::class)->handle($device->device_type, $device->bank_id, $device->terminal_id);
                }
                $lockedFields = array_values(array_filter(['serial_number', 'device_type'], fn (string $f): bool => $device->isDirty($f)));
                $hadCredential = filled($device->device_token);
                if ($lockedFields !== []) {
                    app(RevokeDeviceCredentialsAction::class)->handle($device);
                    $device->forceFill([
                        'serial_verified_at' => null,
                        'status' => $device->status === DeviceStatus::Active ? DeviceStatus::Assigned : $device->status,
                    ]);
                }
                $device->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'device.updated',
                    actorUserId: $actor?->id,
                    companyId: $device->company_id,
                    branchId: $device->branch_id,
                    auditableType: Device::class,
                    auditableId: $device->id,
                    oldValues: $before,
                    newValues: $device->only(array_keys($before)),
                ));

                if ($lockedFields !== []) {
                    $this->writeAuditLog->handle(new AuditLogData(
                        event: 'device.credentials_revoked',
                        actorUserId: $actor?->id,
                        companyId: $device->company_id,
                        branchId: $device->branch_id,
                        auditableType: Device::class,
                        auditableId: $device->id,
                        metadata: [
                            'reason' => 'activation_identity_edited',
                            'changed' => $lockedFields,
                            'had_device_token' => $hadCredential,
                        ],
                    ));
                }
            }

            return $device->refresh();
        });
    }

    /**
     * Drop the Optional (absent) keys so fill() only touches sent fields.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function resolved(array $attributes): array
    {
        return array_filter($attributes, static fn (mixed $value): bool => ! $value instanceof Optional);
    }
}
