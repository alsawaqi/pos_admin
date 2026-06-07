<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
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
                ],
            ));

            return $plainToken;
        });
    }
}
