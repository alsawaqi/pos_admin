<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A Super Admin clears ANOTHER admin's authenticator (LAUNCH-P1 P1-15,
 * owner decision 2026-10-01: "a Super Admin can reset a lost one").
 *
 * Effects, in one transaction:
 *  - secret, recovery codes and confirmation are cleared;
 *  - every session of that admin ends (auth_version bump + remember
 *    token cleared), so a stolen session cannot outlive the reset;
 *  - at the next sign-in the admin is forced through 2FA setup again
 *    (EnsureAdminTwoFactorEnrolled).
 *
 * Refused for one's own account (use the normal disable flow, which
 * needs the password + a code) and for merchant users. Audited with the
 * written reason.
 */
final readonly class ResetTwoFactorAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(User $target, User $actor, string $reason): User
    {
        if ($target->user_type !== UserType::PlatformAdmin) {
            throw new RuntimeException('Two-step login can only be reset for admin users here.');
        }

        if ($target->is($actor)) {
            throw new RuntimeException('You cannot reset your own two-step login here. Use Account security instead.');
        }

        return DB::transaction(function () use ($target, $actor, $reason): User {
            $hadTwoFactor = $target->hasConfirmedTwoFactor();

            $target->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'auth_version' => random_int(1, 9007199254740991),
                'remember_token' => null,
            ])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'platform_user.two_factor_reset_by_admin',
                actorUserId: (int) $actor->id,
                auditableType: User::class,
                auditableId: (int) $target->id,
                oldValues: ['two_factor_enabled' => $hadTwoFactor],
                newValues: ['two_factor_enabled' => false, 'sessions_ended' => true],
                metadata: ['reason' => $reason],
            ));

            return $target->refresh();
        });
    }
}
