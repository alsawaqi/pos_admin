<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\AssignDeviceData;
use App\Data\Security\AuditLogData;
use App\Enums\DeviceStatus;
use App\Http\Requests\Admin\AssignDeviceRequest;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceAssignmentHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Binds an existing {@see Device} to a (company, branch).
 *
 * Three things have to happen atomically:
 *   1. Update the device's company_id, branch_id, assigned_at, status.
 *   2. If there is a currently-open assignment row for this device,
 *      STAMP IT CLOSED with `unassigned_at = now()` before opening a
 *      new one — otherwise the history would show two open rows and
 *      "current assignment" lookups would become ambiguous.
 *   3. Optionally push a geo-fence radius override down to the
 *      branch (blueprint §4.4.3: "Confirm geo-fence radius for this
 *      assignment, default 500 m, editable up to 2000 m").
 *   4. Write an audit log entry tagged `device.assigned`.
 *
 * LAUNCH-P1: the round-up commission profile + organization (P1-9) and the
 * location mode (2a) are part of the assignment. A move to another
 * (company, branch) replaces them with the ones chosen for the new home —
 * nothing of the old merchant's round-up setup travels with the device — and
 * starts the new home in the chosen location mode ('branch' by default). A
 * same-branch re-save keeps whatever it does not send.
 *
 * The whole thing runs inside a DB::transaction so any failure rolls
 * the device + history + branch + audit changes back together — we
 * never want a half-applied assignment that leaves the audit trail
 * inconsistent with the actual fleet state.
 */
final readonly class AssignDeviceAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Device $device, AssignDeviceData $data, ?User $actor = null): Device
    {
        return DB::transaction(function () use ($device, $data, $actor): Device {
            // Read the lifecycle inside the transaction so an intervening block
            // cannot be overwritten by an assignment submitted from an old form.
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);

            // Confirm the chosen branch actually belongs to the chosen
            // company. The FormRequest validates each id exists in its
            // own table — this check is the cross-link the schema can't
            // express on its own.
            /** @var Branch $branch */
            $branch = Branch::query()
                ->whereKey($data->branchId)
                ->where('company_id', $data->companyId)
                ->firstOrFail();

            $identityChanged = (int) $device->company_id !== $data->companyId
                || (int) $device->branch_id !== $data->branchId;

            // P1-9: a new home needs its own round-up settings; a same-branch
            // re-save keeps the current ones unless new ones are sent.
            $commissionProfileId = $data->commissionProfileId ?? ($identityChanged ? null : self::nullableInt($device->commission_profile_id));
            $organizationId = $data->organizationId ?? ($identityChanged ? null : self::nullableInt($device->organization_id));
            if ($identityChanged && ($commissionProfileId === null || $organizationId === null)) {
                throw ValidationException::withMessages(array_filter([
                    'commission_profile_id' => $commissionProfileId === null ? 'Choose the round-up commission profile for this assignment.' : null,
                    'organization_id' => $organizationId === null ? 'Choose the round-up organization for this assignment.' : null,
                ]));
            }

            // 2a: choosing 'branch' (the default for a new home) needs branch
            // coordinates. (A branch cannot lose its coordinates in admin, so a
            // device kept in 'branch' on the same branch stays fenced.)
            $currentMode = $device->location_mode ?? 'branch';
            $locationMode = $data->locationMode ?? ($identityChanged ? 'branch' : $currentMode);
            if ($identityChanged || $locationMode !== $currentMode) {
                ChangeDeviceLocationModeAction::assertAllowed($locationMode, $branch);
            }

            app(AssertDeviceSoftPosAssignment::class)->handle($device->device_type, $data->bankId, $data->terminalId);

            // The bank lock above serializes assignments. Recheck after locking
            // because two requests can both pass FormRequest validation first.
            // Terminal ids compare normalised (the reservation's rule).
            if ($data->bankId !== null && $data->terminalId !== null
                && Device::withTrashed()->where('bank_id', $data->bankId)->whereNotNull('terminal_id')
                    ->whereKeyNot($device->id)->pluck('terminal_id')
                    ->contains(fn ($terminal): bool => ReserveMerchantTerminalAction::normalize((string) $terminal)
                        === ReserveMerchantTerminalAction::normalize($data->terminalId))) {
                throw ValidationException::withMessages(['terminal_id' => AssignDeviceRequest::TERMINAL_TAKEN]);
            }

            // Blank means preserve; only an explicit choice clears the secret.
            $terminalPin = $data->useDefaultPin ? null
                : (filled(trim((string) $data->terminalPin)) ? trim((string) $data->terminalPin)
                    : (((int) $device->bank_id === (int) $data->bankId && $device->terminal_id === $data->terminalId)
                        ? $device->terminal_pin : null));

            // No-op if the device is already on this exact (company, branch)
            // with the same terminal binding and settings. Throwing here keeps
            // the audit log from filling with no-op entries on a double-clicked
            // Save. (A terminal/bank/PIN/round-up/location change on the same
            // branch IS meaningful, so it falls through to re-save.)
            if (! $identityChanged
                && $device->bank_id === $data->bankId
                && $device->terminal_id === $data->terminalId
                && $device->terminal_pin === $terminalPin
                && self::nullableInt($device->commission_profile_id) === $commissionProfileId
                && self::nullableInt($device->organization_id) === $organizationId
                && $currentMode === $locationMode
            ) {
                throw new InvalidArgumentException(
                    'Device is already assigned to this branch.',
                );
            }

            if ($identityChanged) {
                app(AssertDeviceReadyToMove::class)->handle($device, $actor, $data->overrideReason);
                app(RevokeDeviceCredentialsAction::class)->handle($device);
                $device->forceFill(['assignment_activated_at' => null, 'serial_verified_at' => null]);
            }

            if ($data->bankId !== null && $data->terminalId !== null) {
                app(ReserveMerchantTerminalAction::class)->handle($device, $data->companyId, $data->bankId,
                    $data->terminalId, $actor, $data->terminalTransferReason);
            }

            // Snapshot the prior assignment for the audit log before
            // we overwrite the fields. The terminal PIN is a secret —
            // the audit trail only records WHETHER one was set
            // (masked), never the raw value.
            $audited = ['company_id', 'branch_id', 'bank_id', 'terminal_id', 'status',
                'commission_profile_id', 'organization_id', 'location_mode'];
            $before = $device->only($audited);
            $before['terminal_pin'] = $device->terminal_pin !== null ? '••••' : null;

            if ($identityChanged) {
                // 1. Close any currently-open assignment history row.
                DeviceAssignmentHistory::query()
                    ->where('device_id', $device->id)
                    ->whereNull('unassigned_at')
                    ->update([
                        'unassigned_at' => now(),
                        'unassign_reason' => 'Reassigned to another branch',
                    ]);

                // 2. Open a fresh history row for the new assignment.
                DeviceAssignmentHistory::query()->create([
                    'device_id' => $device->id,
                    'company_id' => $data->companyId,
                    'branch_id' => $data->branchId,
                    'assigned_at' => now(),
                    'assigned_by_admin_id' => $actor?->id,
                ]);

                // A new home starts its location mode fresh: nothing made
                // under the old assignment is judged by the new one.
                $device->forceFill([
                    'location_mode' => $locationMode,
                    'location_mode_since' => now(),
                    'location_any_started_at' => $locationMode === 'any' ? now() : null,
                ]);
            } elseif ($currentMode !== $locationMode) {
                app(ChangeDeviceLocationModeAction::class)->switch($device, $locationMode, $actor);
            }

            // 3. Update the device itself with the new bindings — including
            //    the soft-POS terminal (bank_id + terminal_id), captured here
            //    rather than at registration because the terminal is issued
            //    against the merchant's bank account.
            $device->fill([
                'company_id' => $data->companyId,
                'branch_id' => $data->branchId,
                'bank_id' => $data->bankId,
                'terminal_id' => $data->terminalId,
                // Bank-issued Mosambee login PIN — trimmed-or-null
                // (null ⇒ the device uses the vendor default PIN).
                'terminal_pin' => $terminalPin,
                // P1-9: round-up settings of THIS assignment.
                'commission_profile_id' => $commissionProfileId,
                'organization_id' => $organizationId,
                'assigned_by_user_id' => $actor?->id,
                'assigned_at' => $identityChanged ? now() : $device->assigned_at,
                // A bank/terminal edit in the same branch preserves activation.
                // Assignment never lifts an explicit inactive/blocked state;
                // a different branch still requires activation as before.
                'status' => match ($device->status) {
                    DeviceStatus::Inactive, DeviceStatus::Blocked => $device->status,
                    DeviceStatus::Active => $device->company_id === $data->companyId
                        && $device->branch_id === $data->branchId
                            ? DeviceStatus::Active
                            : DeviceStatus::Assigned,
                    default => DeviceStatus::Assigned,
                },
            ]);
            $device->save();

            // 4. Optional geo-fence radius override pushed back to the
            //    branch so every other device at this branch picks up
            //    the same enforcement radius automatically.
            if ($data->geofenceRadiusM !== null && $data->geofenceRadiusM !== $branch->geofence_radius_m) {
                $branch->geofence_radius_m = $data->geofenceRadiusM;
                $branch->save();
            }

            // 5. Audit log the whole thing — old/new snapshots make
            //    "who moved this device where" trivially queryable.
            //    The terminal PIN is masked in BOTH snapshots — the
            //    raw secret must never land in the audit table.
            $after = $device->only($audited);
            $after['terminal_pin'] = $device->terminal_pin !== null ? '••••' : null;

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device.assigned',
                actorUserId: $actor?->id,
                companyId: $data->companyId,
                branchId: $data->branchId,
                auditableType: Device::class,
                auditableId: $device->id,
                oldValues: $before,
                newValues: $after,
            ));

            return $device->refresh();
        });
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
