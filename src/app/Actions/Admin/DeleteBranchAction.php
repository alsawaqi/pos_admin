<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Soft-delete a branch.
 *
 * Safety rail: refuses with a RuntimeException (surfaced as 409 by
 * the controller) when the branch still has at least one device
 * assigned. Soft-deleting a branch with active devices would leave
 * those devices pointing at a phantom row — the right cleanup
 * order is unassign-each-device-then-delete-branch, surfaced as
 * the error message.
 *
 * Soft delete (not hard) because the audit history needs to be able
 * to resolve the branch_id on past events. A subsequent restore is
 * a one-line `$branch->restore()` if the admin changes their mind.
 *
 * Audit event: `branch.deleted`.
 */
final readonly class DeleteBranchAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Branch $branch, User $actor): void
    {
        DB::transaction(function () use ($branch, $actor): void {
            // devices() relation returns active devices only because
            // Device::SoftDeletes scopes out trashed rows by default
            // — which is exactly the check we want (a previously-
            // assigned-and-decommissioned device shouldn't block
            // branch deletion).
            $activeDevices = $branch->devices()->count();
            if ($activeDevices > 0) {
                throw new RuntimeException(
                    "Cannot delete branch with {$activeDevices} active device(s) assigned. Unassign or decommission the devices first.",
                );
            }

            // Snapshot before deletion for the audit log diff.
            $snapshot = $branch->only([
                'uuid', 'name', 'name_ar', 'code', 'company_id', 'status',
            ]);

            $branch->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'branch.deleted',
                actorUserId: $actor->id,
                companyId: $branch->company_id,
                branchId: $branch->id,
                auditableType: Branch::class,
                auditableId: $branch->id,
                oldValues: $snapshot,
                newValues: ['deleted_at' => now()->toIso8601String()],
            ));
        });
    }
}
