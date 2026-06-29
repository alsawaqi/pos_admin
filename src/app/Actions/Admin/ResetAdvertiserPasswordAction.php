<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Advertiser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generate a fresh password for an advertiser and return the plaintext ONCE so
 * the admin can hand it over. Mirrors the merchant portal-user reset-password
 * flow. The new value is hashed by the model's `hashed` cast on save.
 */
final readonly class ResetAdvertiserPasswordAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Advertiser $advertiser, ?User $actor = null): string
    {
        $plain = Str::password(16);

        DB::transaction(function () use ($advertiser, $plain, $actor): void {
            $advertiser->update(['password' => $plain]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'advertiser.password_reset',
                actorUserId: $actor?->id,
                auditableType: Advertiser::class,
                auditableId: $advertiser->id,
            ));
        });

        return $plain;
    }
}
