<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformRole;
use App\Models\Device;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class AssertDeviceReadyToMove
{
    public function handle(Device $device, ?User $actor, ?string $overrideReason = null): void
    {
        // Initial assignment of a never-activated device has no business outbox.
        // An unassigned device was checked when it left its previous identity.
        if ($device->company_id === null || $device->branch_id === null) {
            return;
        }
        $reported = $device->outbox_reported_at;
        $blocked = $device->pending_outbox_count === null
            || (int) $device->pending_outbox_count > 0
            || $reported === null || $reported->lt(now()->subMinutes(15));
        if (! $blocked) {
            return;
        }
        $reason = trim($overrideReason ?? '');
        if ($reason !== '') {
            abort_unless($actor?->isPlatformAdmin() && $actor->hasRole(PlatformRole::SuperAdmin->value), 403);
            app(WriteAuditLogAction::class)->handle(new AuditLogData(
                event: 'device.move_override',
                actorUserId: $actor->id,
                companyId: $device->company_id,
                branchId: $device->branch_id,
                auditableType: Device::class,
                auditableId: $device->id,
                metadata: [
                    'reason' => $reason,
                    'pending_outbox_count' => $device->pending_outbox_count,
                    'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                    'outbox_reported_at' => $reported?->toIso8601String(),
                    'warning' => 'Unsent data will be quarantined, not delivered.',
                ],
            ));

            return;
        }
        $count = $device->pending_outbox_count ?? 'unknown';
        $lastSeen = $device->last_seen_at?->toIso8601String() ?? 'never';
        throw ValidationException::withMessages([
            'device' => "Move refused: pending outbox {$count}; last seen {$lastSeen}. A zero outbox report within 15 minutes is required. A Super Admin override requires a written reason; unsent data will be quarantined, not delivered.",
        ]);
    }
}
