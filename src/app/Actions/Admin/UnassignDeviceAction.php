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
use RuntimeException;

/**
 * Cuts a device loose from its current branch.
 *
 * Used when MITHQAL retrieves a device for repair, or when a branch
 * closes and the kit gets reallocated. The device row stays in the
 * fleet (status drops back to `registered`) so the same physical
 * device can be re-assigned to a new home later without re-creating
 * its kiosk pairing.
 *
 * Behaviour:
 *   1. Find the currently-open assignment history row and stamp it
 *      `unassigned_at = now()` + the optional reason string.
 *   2. Blank the device's company_id / branch_id / assigned_at fields
 *      and demote status back to `registered`.
 *   3. Audit log a `device.unassigned` event with before/after
 *      snapshots so the trail is queryable from the Audit Log viewer.
 *
 * Throws if the device has no open assignment in the first place —
 * that should never happen via the UI but we guard against direct
 * action calls bypassing the front-end gate.
 */
final readonly class UnassignDeviceAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Device $device, ?string $reason = null, ?User $actor = null): Device
    {
        return DB::transaction(function () use ($device, $reason, $actor): Device {
            if ($device->branch_id === null) {
                throw new RuntimeException(
                    'Cannot unassign a device that is not currently assigned.',
                );
            }

            // Snapshot of "where it was" for the audit trail.
            $before = $device->only(['company_id', 'branch_id', 'status']);

            // 1. Close the open history row. There should only ever be
            //    ONE open row per device — if more than one matches the
            //    query (data integrity bug), this still closes them
            //    all, leaving the next assign call free to open one.
            DeviceAssignmentHistory::query()
                ->where('device_id', $device->id)
                ->whereNull('unassigned_at')
                ->update([
                    'unassigned_at' => now(),
                    'unassign_reason' => $reason,
                ]);

            // 2. Strip the device's current assignment fields, INCLUDING the
            //    soft-POS terminal (bank_id + terminal_id) — the terminal was
            //    issued against THIS merchant's bank account, so it must not
            //    travel to the next merchant. Clearing it returns the device
            //    to a clean pool state, free to be re-assigned with a fresh
            //    terminal/bank.
            $device->fill([
                'company_id' => null,
                'branch_id' => null,
                'bank_id' => null,
                'terminal_id' => null,
                'assigned_by_user_id' => null,
                'assigned_at' => null,
                'status' => DeviceStatus::Registered,
            ]);
            $device->save();

            // 3. Audit log the action. company_id + branch_id on the
            //    audit row reference the OLD assignment so audit
            //    queries filtered by company still surface this event
            //    against the right tenant.
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device.unassigned',
                actorUserId: $actor?->id,
                companyId: $before['company_id'],
                branchId: $before['branch_id'],
                auditableType: Device::class,
                auditableId: $device->id,
                oldValues: $before,
                newValues: $device->only(['company_id', 'branch_id', 'status']),
                // The unassign reason lives in the audit log's
                // metadata bag — there is no dedicated note column.
                // Stored under `reason` so the audit viewer can
                // surface it next to the diff.
                metadata: $reason !== null ? ['reason' => $reason] : null,
            ));

            return $device->refresh();
        });
    }
}
