<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LAUNCH-P1 decision 2a — a device works either at "this branch location"
 * ('branch', geofenced, the default) or at "any location" ('any').
 *
 * pos_api judges every location-checked action by this mode
 * (GeofenceGuard::requirement). Switching any → branch stamps
 * location_mode_since and keeps the start of the 'any' period in
 * location_any_started_at, so sales the device made while it was still 'any'
 * (and that reach the server later) are not refused.
 *
 * 'branch' needs a branch with coordinates: the server refuses a 'branch'
 * device at a branch without a location rather than leave it unfenced.
 *
 * Audited as `device.location_mode_changed`.
 */
final readonly class ChangeDeviceLocationModeAction
{
    public const MODES = ['branch', 'any'];

    public function __construct(private WriteAuditLogAction $writeAuditLog) {}

    public function handle(Device $device, string $mode, ?User $actor = null): Device
    {
        return DB::transaction(function () use ($device, $mode, $actor): Device {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($device->company_id === null || $device->branch_id === null) {
                throw ValidationException::withMessages(['location_mode' => 'Assign the device to a branch first.']);
            }
            self::assertAllowed($mode, Branch::query()->find($device->branch_id));
            if (($device->location_mode ?? 'branch') === $mode) {
                return $device;
            }
            $this->switch($device, $mode, $actor);
            $device->save();

            return $device->refresh();
        });
    }

    /** Throws when the mode is unknown, or 'branch' at a branch without coordinates. */
    public static function assertAllowed(string $mode, ?Branch $branch): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages(['location_mode' => 'Choose "This branch location" or "Any location".']);
        }
        if ($mode === 'branch' && ($branch === null || $branch->latitude === null || $branch->longitude === null)) {
            throw ValidationException::withMessages([
                'location_mode' => 'This branch has no location set. Set the branch location first, or choose "Any location".',
            ]);
        }
    }

    /** Applies a same-assignment mode change (caller locks + saves) and audits it. */
    public function switch(Device $device, string $mode, ?User $actor): void
    {
        $before = ['location_mode' => $device->location_mode ?? 'branch',
            'location_mode_since' => $device->location_mode_since?->toIso8601String()];
        $now = now();
        $device->forceFill([
            'location_mode' => $mode,
            'location_mode_since' => $now,
            // Keep the start of the 'any' period that is ending, so its late
            // events are judged as made while 'any'; a new 'any' period starts now.
            'location_any_started_at' => $mode === 'any' ? $now
                : ($before['location_mode'] === 'any'
                    ? ($device->location_mode_since ?? $device->location_any_started_at ?? $device->assigned_at ?? $now)
                    : null),
        ]);

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'device.location_mode_changed',
            actorUserId: $actor?->id,
            companyId: $device->company_id,
            branchId: $device->branch_id,
            auditableType: Device::class,
            auditableId: $device->id,
            oldValues: $before,
            newValues: ['location_mode' => $mode, 'location_mode_since' => $now->toIso8601String()],
        ));
    }
}
