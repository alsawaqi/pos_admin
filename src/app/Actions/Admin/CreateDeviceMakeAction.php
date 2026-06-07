<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\DeviceMake;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Insert a new device-make row + write an audit log entry, atomic.
 * Mirrors the {@see CreateBusinessActivityAction} pattern.
 */
final readonly class CreateDeviceMakeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, ?User $actor = null): DeviceMake
    {
        return DB::transaction(function () use ($attributes, $actor): DeviceMake {
            /** @var DeviceMake $make */
            $make = DeviceMake::query()->create([
                'name' => $attributes['name'],
                'display_order' => $attributes['display_order'] ?? 0,
                'is_active' => $attributes['is_active'] ?? true,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device_make.created',
                actorUserId: $actor?->id,
                // No company_id — platform-wide reference data.
                auditableType: DeviceMake::class,
                auditableId: $make->id,
                newValues: $make->only(['name', 'display_order', 'is_active']),
            ));

            return $make;
        });
    }
}
