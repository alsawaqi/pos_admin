<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserType;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\Auth\SetPasswordLink;
use Illuminate\Support\Facades\DB;

/**
 * An admin resets someone's password — a merchant portal user or
 * another admin (LAUNCH-P1 P1-2 / P1-8, owner follow-up 2026-10-01).
 *
 * The OLD PASSWORD STOPS WORKING AT ONCE: it is removed (NULL), every
 * session ends (auth_version + remember token rotate), and only the new
 * single-use link can set a password again (60 minutes). Signing in with
 * the old password is answered with "use the set-password link" (see
 * the login controllers).
 *
 * A user who has no password yet (never set one, or already reset) just
 * gets a fresh link of the kind they were waiting for: another 72-hour
 * invite, or another 60-minute reset.
 *
 * Audit: `portal_user.password_reset` / `platform_user.password_reset`
 * plus the `*.set_password_link_issued` row. No password or token
 * material is ever logged.
 */
final readonly class ResetPasswordWithLinkAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private IssueSetPasswordLinkAction $issueLink,
    ) {}

    /**
     * @return array{user: User, link: SetPasswordLink}
     */
    public function handle(User $user, ?User $actor = null): array
    {
        $hadPassword = $user->password !== null;

        if ($hadPassword) {
            DB::transaction(function () use ($user, $actor): void {
                $user->forceFill([
                    'password' => null,
                    'remember_token' => null,
                    'auth_version' => random_int(1, 9007199254740991),
                ])->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: ($user->user_type === UserType::Merchant ? 'portal_user' : 'platform_user').'.password_reset',
                    actorUserId: $actor?->id,
                    companyId: $user->company_id === null ? null : (int) $user->company_id,
                    auditableType: User::class,
                    auditableId: (int) $user->id,
                    newValues: [
                        'reset_at' => now()->toIso8601String(),
                        'method' => 'set_password_link',
                        'old_password_blocked' => true,
                        'sessions_ended' => true,
                    ],
                ));
            });
        }

        $purpose = $hadPassword
            ? PasswordResetToken::PURPOSE_RESET
            : IssueSetPasswordLinkAction::purposeForUserWithoutPassword($user);

        $link = $this->issueLink->handle($user, $purpose, $actor);

        return ['user' => $user->refresh(), 'link' => $link];
    }
}
