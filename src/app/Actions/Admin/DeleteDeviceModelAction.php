<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\DeviceModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hard-delete a device-model row.
 *
 * Refuses when any device still references it — admin should
 * DEACTIVATE instead so historical device records keep working.
 */
final readonly class DeleteDeviceModelAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(DeviceModel $model, ?User $actor = null): void
    {
        DB::transaction(function () use ($model, $actor): void {
            if ($model->devices()->exists()) {
                throw new RuntimeException(
                    'Cannot delete a device model that is still in use by one or more devices. Deactivate it instead.',
                );
            }

            $snapshot = array_merge(
                $model->only(['name', 'code', 'display_order', 'is_active']),
                ['make_id' => $model->make_id],
            );

            $model->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device_model.deleted',
                actorUserId: $actor?->id,
                auditableType: DeviceModel::class,
                auditableId: $model->id,
                oldValues: $snapshot,
            ));
        });
    }
}
