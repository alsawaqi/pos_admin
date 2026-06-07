<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\DeviceModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PATCH a device-model row. Same no-op-when-clean pattern as the
 * other catalogue update actions.
 */
final readonly class UpdateDeviceModelAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(DeviceModel $model, array $attributes, ?User $actor = null): DeviceModel
    {
        return DB::transaction(function () use ($model, $attributes, $actor): DeviceModel {
            $before = $model->only(['name', 'code', 'display_order', 'is_active']);

            $model->fill($attributes);

            if (! $model->isDirty()) {
                return $model;
            }

            $model->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device_model.updated',
                actorUserId: $actor?->id,
                auditableType: DeviceModel::class,
                auditableId: $model->id,
                oldValues: $before,
                newValues: $model->only(array_keys($before)),
            ));

            return $model->refresh();
        });
    }
}
