<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\AssignDeviceData;
use App\Data\Security\AuditLogData;
use App\Enums\DeviceStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceAssignmentHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Binds an existing {@see Device} to a (company, branch).
 *
 * Three things have to happen atomically:
 *   1. Update the device's company_id, branch_id, assigned_at, status.
 *   2. If there is a currently-open assignment row for this device,
 *      STAMP IT CLOSED with `unassigned_at = now()` before opening a
 *      new one — otherwise the history would show two open rows and
 *      "current assignment" lookups would become ambiguous.
 *   3. Optionally push a geo-fence radius override down to the
 *      branch (blueprint §4.4.3: "Confirm geo-fence radius for this
 *      assignment, default 500 m, editable up to 2000 m").
 *   4. Write an audit log entry tagged `device.assigned`.
 *
 * The whole thing runs inside a DB::transaction so any failure rolls
 * the device + history + branch + audit changes back together — we
 * never want a half-applied assignment that leaves the audit trail
 * inconsistent with the actual fleet state.
 */
final readonly class AssignDeviceAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Device $device, AssignDeviceData $data, ?User $actor = null): Device
    {
        return DB::transaction(function () use ($device, $data, $actor): Device {
            // Confirm the chosen branch actually belongs to the chosen
            // company. The FormRequest validates each id exists in its
            // own table — this check is the cross-link the schema can't
            // express on its own.
            /** @var Branch $branch */
            $branch = Branch::query()
                ->whereKey($data->branchId)
                ->where('company_id', $data->companyId)
                ->firstOrFail();

            // No-op if the device is already on this exact (company,
            // branch). Throwing here keeps the audit log from filling
            // with no-op entries every time an admin double-clicks
            // Save on the Assign page.
            if ($device->company_id === $data->companyId && $device->branch_id === $data->branchId) {
                throw new InvalidArgumentException(
                    'Device is already assigned to this branch.',
                );
            }

            // Snapshot the prior assignment for the audit log before
            // we overwrite the fields.
            $before = $device->only(['company_id', 'branch_id', 'status']);

            // 1. Close any currently-open assignment history row.
            DeviceAssignmentHistory::query()
                ->where('device_id', $device->id)
                ->whereNull('unassigned_at')
                ->update([
                    'unassigned_at' => now(),
                    'unassign_reason' => 'Reassigned to another branch',
                ]);

            // 2. Open a fresh history row for the new assignment.
            DeviceAssignmentHistory::query()->create([
                'device_id' => $device->id,
                'company_id' => $data->companyId,
                'branch_id' => $data->branchId,
                'assigned_at' => now(),
                'assigned_by_admin_id' => $actor?->id,
            ]);

            // 3. Update the device itself with the new bindings.
            $device->fill([
                'company_id' => $data->companyId,
                'branch_id' => $data->branchId,
                'assigned_by_user_id' => $actor?->id,
                'assigned_at' => now(),
                // If the device was offline/blocked, becoming assigned
                // again does not promote it back to active by itself —
                // active is reserved for "we got a heartbeat". So we
                // set it to assigned here and let the heartbeat path
                // bump it to active when the device next checks in.
                'status' => DeviceStatus::Assigned,
            ]);
            $device->save();

            // 4. Optional geo-fence radius override pushed back to the
            //    branch so every other device at this branch picks up
            //    the same enforcement radius automatically.
            if ($data->geofenceRadiusM !== null && $data->geofenceRadiusM !== $branch->geofence_radius_m) {
                $branch->geofence_radius_m = $data->geofenceRadiusM;
                $branch->save();
            }

            // 5. Audit log the whole thing — old/new snapshots make
            //    "who moved this device where" trivially queryable.
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device.assigned',
                actorUserId: $actor?->id,
                companyId: $data->companyId,
                branchId: $data->branchId,
                auditableType: Device::class,
                auditableId: $device->id,
                oldValues: $before,
                newValues: $device->only(['company_id', 'branch_id', 'status']),
            ));

            return $device->refresh();
        });
    }
}
