<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceActivationToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateDeviceActivationTokenAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Device $device, ?User $actor = null, int $ttlMinutes = 30): string
    {
        return DB::transaction(function () use ($device, $actor, $ttlMinutes): string {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            abort_unless($device->company_id !== null && $device->branch_id !== null
                && ! in_array($device->status, [DeviceStatus::Blocked, DeviceStatus::Inactive], true), 409);
            // LAUNCH-P1 low: only the newest code works. Older unused codes for
            // this device are revoked, so a code photographed earlier dies.
            $revokedPrevious = $device->activationTokens()->whereNull('used_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            $plainToken = 'mithqal_'.Str::random(64);
            $expiresAt = Carbon::now()->addMinutes($ttlMinutes);

            /** @var DeviceActivationToken $activationToken */
            $activationToken = DeviceActivationToken::query()->create([
                'device_id' => $device->id,
                'token_hash' => hash('sha256', $plainToken),
                'created_by_user_id' => $actor?->id,
                'expires_at' => $expiresAt,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'device.activation_token.created',
                actorUserId: $actor?->id,
                companyId: $device->company_id,
                branchId: $device->branch_id,
                auditableType: DeviceActivationToken::class,
                auditableId: $activationToken->id,
                metadata: [
                    'device_id' => $device->id,
                    'expires_at' => $expiresAt->toISOString(),
                    'revoked_previous_codes' => $revokedPrevious,
                ],
            ));

            return $plainToken;
        });
    }
}
