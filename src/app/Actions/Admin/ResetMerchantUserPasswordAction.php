<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Auth\ResetPasswordWithLinkAction;
use App\Enums\UserType;
use App\Models\User;
use App\Support\Auth\SetPasswordLink;
use RuntimeException;

/**
 * "Send set-password link" for a merchant portal user (LAUNCH-P1 P1-2).
 *
 * Replaces the old "generate a 20-character password and show it once":
 * no admin ever sees or hands over a merchant password.
 *
 *  - The user has a password → it is blocked at once, every session
 *    ends, and a 60-minute reset link is issued (owner follow-up
 *    2026-10-01: only the link works from now on).
 *  - The user has no password (never set one, or already reset) → a
 *    fresh link of the same kind is resent; older links die.
 *
 * See {@see ResetPasswordWithLinkAction} (shared with admin users).
 */
final readonly class ResetMerchantUserPasswordAction
{
    public function __construct(
        private ResetPasswordWithLinkAction $resetWithLink,
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

        return $this->resetWithLink->handle($user, $actor);
    }
}
