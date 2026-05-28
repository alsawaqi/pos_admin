<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Mail\MerchantPortalWelcomeMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Re-issue the welcome / setup email for an existing portal user.
 *
 * Use case: the original invite expired before the recipient got
 * to it, or the email got buried in their inbox and they want a
 * fresh link. Generates a NEW raw token + hash (invalidating any
 * prior one) and resets the 7-day expiry, then re-dispatches the
 * welcome email.
 *
 * Refuses if the account has already completed setup (password is
 * set) — at that point the user should use "Forgot password" on
 * the merchant portal instead, which is a separate flow.
 */
final readonly class ResendPortalInviteAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Company $company, User $portalUser, ?User $actor = null): User
    {
        return DB::transaction(function () use ($company, $portalUser, $actor): User {
            // Belt-and-braces tenant check: the URL-bound company
            // should match the portal user's company. Anything else
            // is a routing bug.
            if ($portalUser->company_id !== $company->id) {
                throw new RuntimeException(
                    'Portal user does not belong to the specified company.',
                );
            }

            // If they've already set a password, this isn't an
            // invite anymore — they need the regular password-reset
            // flow.
            if ($portalUser->password !== null) {
                throw new RuntimeException(
                    'This user has already completed setup. Use the password-reset flow instead.',
                );
            }

            // Roll a fresh token. SHA-256 hashed in storage; the
            // raw token only ever appears in the email body.
            $rawToken = Str::random(64);
            $hashedToken = hash('sha256', $rawToken);
            $expiresAt = now()->addDays(7);

            $portalUser->setup_token_hash = $hashedToken;
            $portalUser->setup_token_expires_at = $expiresAt;
            $portalUser->invited_at = now();
            $portalUser->invited_by_admin_id = $actor?->id;
            $portalUser->save();

            Mail::to($portalUser->email)->send(
                new MerchantPortalWelcomeMail($portalUser, $company, $rawToken, $expiresAt),
            );

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.invite_resent',
                actorUserId: $actor?->id,
                companyId: $company->id,
                auditableType: User::class,
                auditableId: $portalUser->id,
                newValues: ['invited_at' => $portalUser->invited_at?->toIso8601String()],
            ));

            return $portalUser->refresh();
        });
    }
}
