<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\RegisterDeviceData;
use App\Data\Security\AuditLogData;
use App\Enums\DeviceStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceAssignmentHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a new {@see Device} row keyed by scalefusion kiosk id.
 *
 * Most calls just register the device (status=registered, no company,
 * no branch). The flow supports an optional immediate assignment in
 * the same call — older test scripts use this shortcut. When both
 * companyId and branchId are present the action ALSO opens the first
 * row of the device's assignment history ledger and stamps the
 * status as `assigned`.
 *
 * Whole thing runs in a transaction so a failed audit-log write rolls
 * the device creation back — we never want a half-formed registration.
 *
 * Audit log event: `device.registered` (plus `device.assigned` when
 * the optional assignment fires).
 */
final readonly class RegisterDeviceAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(RegisterDeviceData $data, ?User $actor = null): Device
    {
        return DB::transaction(function () use ($data, $actor): Device {
            // Defensive: branch without company is meaningless because
            // branches always belong to a company. The FormRequest
            // catches this at the API layer, but other callers (tests,
            // seeders) hit this Action directly so we re-assert here.
            if ($data->branchId !== null && $data->companyId === null) {
                throw new InvalidArgumentException(
                    'A branch-assigned device must also include a company id.',
                );
            }

            // If a branch was passed in, make sure it really belongs
            // to the claimed company. Without this an admin could
            // accidentally bind a device to one company's id while
            // pointing branch_id at another company's branch row.
            $branch = null;
            if ($data->branchId !== null) {
                /** @var Branch $branch */
                $branch = Branch::query()
                    ->whereKey($data->branchId)
                    ->where('company_id', $data->companyId)
                    ->firstOrFail();
            }

            // Status reflects whether we landed in "registered, no
            // home" or "registered + immediately assigned to a branch".
            $status = $branch instanceof Branch
                ? DeviceStatus::Assigned
                : DeviceStatus::Registered;

            /** @var Device $device */
            $device = Device::query()->create([
                'uuid' => (string) Str::uuid(),
                'serial_number' => $data->serialNumber,
                'kiosk_id' => $data->kioskId,
                // Bank-issued terminal id + the chosen commission
                // profile FK + acquiring bank FK. All validated by
                // the FormRequest (unique + exists respectively).
                'terminal_id' => $data->terminalId,
                'commission_profile_id' => $data->commissionProfileId,
                'bank_id' => $data->bankId,
                'name' => $data->name,
                'label' => $data->label,
                // Catalogue FKs — replaces the legacy free-text model
                // string. The FormRequest already validated that the
                // model belongs to the make, so we can write both
                // without re-checking.
                'make_id' => $data->makeId,
                'model_id' => $data->modelId,
                'device_type' => $data->deviceType,
                'company_id' => $data->companyId,
                'branch_id' => $data->branchId,

                // Who clicked Register, and (if assignment piggy-backed
                // on the same call) who triggered the assignment too.
                'registered_by_user_id' => $actor?->id,
                'assigned_by_user_id' => $branch instanceof Branch ? $actor?->id : null,
                'status' => $status,
                'assigned_at' => $branch instanceof Branch ? now() : null,

                'app_version' => $data->appVersion,
                'firmware_version' => $data->firmwareVersion,
                'metadata' => $data->metadata,
            ]);

            // Open the first history row when registration came with
            // an immediate assignment. Reassignments later go through
            // {@see AssignDeviceAction} which is responsible for
            // closing prior rows first.
            if ($branch instanceof Branch) {
                DeviceAssignmentHistory::query()->create([
                    'device_id' => $device->id,
                    'company_id' => $data->companyId,
                    'branch_id' => $data->branchId,
                    'assigned_at' => now(),
                    'assigned_by_admin_id' => $actor?->id,
                ]);
            }

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
                    'kiosk_id',
                    'terminal_id',
                    'commission_profile_id',
                    'bank_id',
                    'device_type',
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
