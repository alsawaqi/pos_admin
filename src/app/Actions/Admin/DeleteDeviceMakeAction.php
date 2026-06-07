<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\DeviceMake;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hard-delete a device-make row.
 *
 * Refuses with a clear message when:
 *   - any device is still registered against this make
 *   - the make still has child model rows (admin should delete the
 *     models first, or rely on the cascade which only fires after
 *     this guard clears)
 *
 * Admins should DEACTIVATE (is_active=false) instead of delete when
 * a make has historical devices — that hides it from the Register
 * Device dropdown without breaking the existing fleet records.
 */
final readonly class DeleteDeviceMakeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(DeviceMake $make, ?User $actor = null): void
    {
        DB::transaction(function () use ($make, $actor): void {
            if ($make->devices()->exists()) {
                throw new RuntimeException(
                    'Cannot delete a device make that is still in use by one or more devices. Deactivate it instead.',
                );
            }
            if ($make->models()->exists()) {
                throw new RuntimeException(
                    'This make still has models. Delete the models first or deactivate the make.',
                );
            }

            $snapshot = $make->only(['name', 'display_order', 'is_active']);
            $make->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device_make.deleted',
                actorUserId: $actor?->id,
                auditableType: DeviceMake::class,
                auditableId: $make->id,
                oldValues: $snapshot,
            ));
        });
    }
}
