<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Device;
use Illuminate\Support\Facades\Schema;

final class RevokeDeviceCredentialsAction
{
    /** Caller holds the device row lock inside its lifecycle transaction. */
    public function handle(Device $device): void
    {
        $device->forceFill([
            'device_token' => null,
            'token_company_id' => null,
            'token_branch_id' => null,
        ]);
        $device->activationTokens()->whereNull('used_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        // Legacy Sanctum device tokens, where that optional table is installed.
        if (Schema::hasTable('personal_access_tokens')) {
            $device->tokens()->delete();
        }
    }
}
