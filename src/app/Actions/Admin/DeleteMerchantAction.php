<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Soft-delete a merchant company.
 *
 * Safety rails (both surfaced as 409 by the controller):
 *   - Refuses if the merchant has any active branches.
 *   - Refuses if the merchant has any active devices.
 *
 * The "clean up children first" semantic is intentional: a cascade
 * delete would silently take a fleet of devices offline and free
 * up valuable terminal_ids, with no surface for the admin to
 * realize what happened. Forcing the explicit child cleanup keeps
 * the destructive blast radius visible at every step.
 *
 * Soft delete (not hard) because the audit history references
 * company_id on every past event — hard-deleting would orphan
 * those rows. A subsequent restore is `$company->restore()`.
 *
 * Audit event: `company.deleted`.
 */
final readonly class DeleteMerchantAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Company $company, User $actor): void
    {
        DB::transaction(function () use ($company, $actor): void {
            // branches() / devices() return active rows only (soft
            // deletes hide trashed rows). That's the right semantic
            // — "delete if no LIVE children". A merchant that's
            // had every device decommissioned can be cleaned up.
            $branchesCount = $company->branches()->count();
            $devicesCount = $company->devices()->count();

            if ($branchesCount > 0 || $devicesCount > 0) {
                throw new RuntimeException(sprintf(
                    'Cannot delete merchant with %d active branch(es) and %d active device(s). Clean those up first.',
                    $branchesCount,
                    $devicesCount,
                ));
            }

            $snapshot = $company->only([
                'uuid', 'name', 'name_ar', 'cr_number', 'status',
            ]);

            $company->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'company.deleted',
                actorUserId: $actor->id,
                companyId: $company->id,
                auditableType: Company::class,
                auditableId: $company->id,
                oldValues: $snapshot,
                newValues: ['deleted_at' => now()->toIso8601String()],
            ));
        });
    }
}
