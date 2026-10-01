<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Admin portal "Forgot password?" (LAUNCH-P1 P1-8).
 *
 * Silent by design: the public endpoint answers 200 whatever happens,
 * so it never reveals which emails are admins. Only ACTIVE platform
 * admins get a link (merchant rows are never considered — the merchant
 * portal has its own forgot-password). One link per admin per minute,
 * independent of the per-IP throttle, so a mailbox cannot be flooded.
 *
 * The link is the 60-minute "forgot" purpose; it replaces only older
 * forgot links — an invite or reset link an admin issued stays valid
 * (anyone can type an email here). Mail is sent only when real mail is
 * configured ({@see IssueSetPasswordLinkAction}).
 */
final readonly class SendAdminPasswordResetLinkAction
{
    private const MINT_COOLDOWN_SECONDS = 60;

    public function __construct(
        private IssueSetPasswordLinkAction $issueLink,
    ) {}

    public function handle(string $email): void
    {
        $user = User::query()
            ->platformAdmin()
            ->where('email', Str::lower($email))
            ->where('status', 'active')
            ->first();

        if ($user === null) {
            return;
        }

        $recentlyMinted = PasswordResetToken::query()
            ->where('user_id', $user->id)
            ->where('purpose', PasswordResetToken::PURPOSE_FORGOT)
            ->where('created_at', '>=', now()->subSeconds(self::MINT_COOLDOWN_SECONDS))
            ->exists();

        if ($recentlyMinted) {
            return;
        }

        $this->issueLink->handle($user, PasswordResetToken::PURPOSE_FORGOT);
    }
}
