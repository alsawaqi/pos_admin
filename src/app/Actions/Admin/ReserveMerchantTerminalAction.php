<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformRole;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReserveMerchantTerminalAction
{
    /**
     * The one terminal-ID comparison rule: trim, no whitespace, upper case.
     * Also used by the assign uniqueness checks (LAUNCH-P1 low).
     */
    public static function normalize(string $terminalId): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim($terminalId)));
    }

    /** Caller holds the bank lock acquired by AssertDeviceSoftPosAssignment. */
    public function handle(Device $device, int $companyId, int $bankId, string $terminalId, ?User $actor, ?string $reason): void
    {
        $terminal = self::normalize($terminalId);
        $prior = DB::table('pos_terminal_reservations')->where('bank_id', $bankId)
            ->where('terminal_id', $terminal)->lockForUpdate()->first();
        if ($prior !== null && (int) $prior->company_id !== $companyId) {
            abort_unless($actor?->isPlatformAdmin() && $actor->hasRole(PlatformRole::SuperAdmin->value), 403);
            if (trim($reason ?? '') === '') {
                throw ValidationException::withMessages(['terminal_transfer_reason' => 'A written reason is required to transfer a bank terminal between merchants.']);
            }
            app(WriteAuditLogAction::class)->handle(new AuditLogData(
                event: 'device.terminal_merchant_transfer', actorUserId: $actor->id,
                auditableType: Device::class, auditableId: $device->id,
                oldValues: ['company_id' => $prior->company_id, 'bank_id' => $bankId, 'terminal_id' => $terminal],
                newValues: ['company_id' => $companyId],
                metadata: ['reason' => trim($reason)],
            ));
        }
        DB::table('pos_terminal_reservations')->updateOrInsert(
            ['bank_id' => $bankId, 'terminal_id' => $terminal],
            ['company_id' => $companyId, 'device_id' => $device->id, 'created_at' => $prior?->created_at ?? now(), 'updated_at' => now()],
        );
    }
}
