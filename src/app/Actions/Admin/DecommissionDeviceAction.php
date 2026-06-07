<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceAssignmentHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Permanently remove a device from the active fleet.
 *
 * Flow:
 *   1. If the device has an open assignment history row, close it
 *      with `unassign_reason = 'decommissioned'` so the ledger
 *      reflects WHY the assignment ended.
 *   2. Flip status to Blocked so any future API surface that
 *      filters by status (dashboard tiles, list pages) treats it
 *      consistently.
 *   3. Soft-delete the device row so default queries hide it
 *      across the platform without losing the audit history.
 *
 * Idempotent: a re-decommission of an already-soft-deleted device
 * is a no-op. The audit event still fires though, so the trail
 * shows that an admin re-confirmed the action — useful for
 * forensic timelines.
 *
 * Permission gating happens at the controller via DevicePolicy::
 * decommission (which checks the existing DevicesDecommission
 * permission).
 *
 * Audit event: `device.decommissioned`.
 */
final readonly class DecommissionDeviceAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Device $device, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($device, $actor, $reason): void {
            $previousStatus = $device->status?->value;
            $previousCompanyId = $device->company_id;
            $previousBranchId = $device->branch_id;

            // 1. Close any open assignment row so the history
            //    ledger reflects the decommission. Note: the
            //    pos_device_assignments_history table is intentionally
            //    append-only (no updated_at column) — only the
            //    closure fields are mutable, so don't include any
            //    auto-timestamp keys in the update payload.
            DeviceAssignmentHistory::query()
                ->where('device_id', $device->id)
                ->whereNull('unassigned_at')
                ->update([
                    'unassigned_at' => now(),
                    'unassign_reason' => $reason ?? 'decommissioned',
                ]);

            // 2. Status flip + persist. Done BEFORE the soft delete
            //    so the row's last-known status is recorded even
            //    when retrieved via withTrashed().
            $device->status = DeviceStatus::Blocked;
            $device->save();

            // 3. Soft delete — default queries hide the row, audit
            //    log still resolves it via withTrashed.
            $device->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device.decommissioned',
                actorUserId: $actor->id,
                companyId: $previousCompanyId,
                branchId: $previousBranchId,
                auditableType: Device::class,
                auditableId: $device->id,
                oldValues: [
                    'status' => $previousStatus,
                    'company_id' => $previousCompanyId,
                    'branch_id' => $previousBranchId,
                ],
                newValues: [
                    'status' => DeviceStatus::Blocked->value,
                    'deleted_at' => now()->toIso8601String(),
                    'reason' => $reason,
                ],
            ));
        });
    }
}
