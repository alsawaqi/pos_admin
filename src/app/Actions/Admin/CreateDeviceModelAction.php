<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\DeviceMake;
use App\Models\DeviceModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Insert a new device-model row under a parent make. The Action
 * binds the model to the URL-bound make so the controller never
 * has to worry about cross-make confusion.
 */
final readonly class CreateDeviceModelAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(DeviceMake $make, array $attributes, ?User $actor = null): DeviceModel
    {
        return DB::transaction(function () use ($make, $attributes, $actor): DeviceModel {
            /** @var DeviceModel $model */
            $model = DeviceModel::query()->create([
                'make_id' => $make->id,
                'name' => $attributes['name'],
                'code' => $attributes['code'] ?? null,
                'display_order' => $attributes['display_order'] ?? 0,
                'is_active' => $attributes['is_active'] ?? true,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device_model.created',
                actorUserId: $actor?->id,
                auditableType: DeviceModel::class,
                auditableId: $model->id,
                newValues: array_merge(
                    $model->only(['name', 'code', 'display_order', 'is_active']),
                    ['make_id' => $make->id, 'make_name' => $make->name],
                ),
            ));

            return $model;
        });
    }
}
