<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Auth\IssueSetPasswordLinkAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserType;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\Auth\SetPasswordLink;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Send set-password link" for a merchant portal user (LAUNCH-P1 P1-2).
 *
 * Replaces the old "generate a 20-character password and show it once":
 * no admin ever sees or hands over a merchant password.
 *
 *  - The user never set a password yet → RESEND the invite link
 *    (72 hours); the older link dies.
 *  - The user has a password → an admin RESET link (60 minutes), and
 *    every open session of that user ends now (auth_version bump +
 *    remember token cleared — LAUNCH-P1 low finding). The old password
 *    keeps working until the link is used, so a mistaken click does not
 *    lock the merchant out; to cut access at once, suspend the user.
 *
 * Audit: `portal_user.password_reset` (reset only) plus
 * `portal_user.set_password_link_issued`. No password or token material
 * is ever written to the audit log.
 */
final readonly class ResetMerchantUserPasswordAction
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
        // Defensive: the route scope-binds the user to the merchant, but
        // a platform admin's row must never be reset through here.
        if ($user->user_type !== UserType::Merchant) {
            throw new RuntimeException(
                'Cannot reset password — this user is not a merchant portal user.',
            );
        }

        $neverSetPassword = $user->password === null;

        if (! $neverSetPassword) {
            DB::transaction(function () use ($user, $actor): void {
                // End every session of this user (pos_merchant's
                // EnsureUserAccess compares the session's auth_version).
                $user->forceFill([
                    'auth_version' => random_int(1, 9007199254740991),
                    'remember_token' => null,
                ])->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'portal_user.password_reset',
                    actorUserId: $actor?->id,
                    companyId: $user->company_id,
                    auditableType: User::class,
                    auditableId: $user->id,
                    newValues: [
                        'reset_at' => now()->toIso8601String(),
                        'method' => 'set_password_link',
                        'sessions_ended' => true,
                    ],
                ));
            });
        }

        $link = $this->issueLink->handle(
            $user,
            $neverSetPassword ? PasswordResetToken::PURPOSE_INVITE : PasswordResetToken::PURPOSE_RESET,
            $actor,
        );

        return ['user' => $user->refresh(), 'link' => $link];
    }
}
