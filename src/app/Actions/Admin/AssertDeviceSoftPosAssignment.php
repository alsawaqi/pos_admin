<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\DeviceType;
use App\Enums\SoftPosProvider;
use App\Models\Bank;
use App\Models\BankSoftPosProfile;
use Illuminate\Validation\ValidationException;

final class AssertDeviceSoftPosAssignment
{
    public function handle(?DeviceType $type, ?int $bankId, ?string $terminalId): void
    {
        if ($type !== DeviceType::PaymentStation && trim($terminalId ?? '') === '') {
            return;
        }

        // Same bank lock as profile edits: a provider cannot be disabled between
        // this check and the surrounding assignment transaction's commit.
        $bank = $bankId === null ? null : Bank::query()->whereKey($bankId)->lockForUpdate()->first();
        $profile = $bank === null ? null : BankSoftPosProfile::query()
            ->where('bank_id', $bankId)->lockForUpdate()->first();
        if ($profile === null || ! $profile->is_active || $profile->softpos_provider === SoftPosProvider::None) {
            throw ValidationException::withMessages([
                'bank_id' => 'This bank needs an active card terminal app profile before a card terminal can be assigned.',
            ]);
        }
    }
}
