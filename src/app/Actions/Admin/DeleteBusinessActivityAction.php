<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\BusinessActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hard-delete a business activity row. Refuses when the activity is
 * still attached to any merchant via the pos_company_activities
 * pivot — in that case the admin should DEACTIVATE the row (flip
 * is_active to false) instead of deleting, so historical merchant
 * profiles remain intact.
 */
final readonly class DeleteBusinessActivityAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(BusinessActivity $activity, ?User $actor = null): void
    {
        DB::transaction(function () use ($activity, $actor): void {
            // Guard: don't allow deletion if any merchant is still
            // pinned to this activity. The pivot table has a cascade
            // FK so blindly deleting would silently rewrite merchant
            // history.
            $inUse = $activity->companies()->exists();
            if ($inUse) {
                throw new RuntimeException(
                    'Cannot delete a business activity that is still assigned to one or more merchants. Deactivate it instead.',
                );
            }

            $snapshot = $activity->only([
                'code', 'name_en', 'name_ar', 'category',
                'is_active', 'display_order',
            ]);

            $activity->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'business_activity.deleted',
                actorUserId: $actor?->id,
                auditableType: BusinessActivity::class,
                auditableId: $activity->id,
                oldValues: $snapshot,
            ));
        });
    }
}
