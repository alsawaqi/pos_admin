<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\DeviceMake;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PATCH a device-make row. Skips DB write when isDirty() is false
 * (no-op saves shouldn't produce audit entries).
 */
final readonly class UpdateDeviceMakeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(DeviceMake $make, array $attributes, ?User $actor = null): DeviceMake
    {
        return DB::transaction(function () use ($make, $attributes, $actor): DeviceMake {
            $before = $make->only(['name', 'display_order', 'is_active']);

            $make->fill($attributes);

            if (! $make->isDirty()) {
                return $make;
            }

            $make->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device_make.updated',
                actorUserId: $actor?->id,
                auditableType: DeviceMake::class,
                auditableId: $make->id,
                oldValues: $before,
                newValues: $make->only(array_keys($before)),
            ));

            return $make->refresh();
        });
    }
}
