<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Mail\MerchantPortalWelcomeMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Invite a new merchant portal user (blueprint §4.5).
 *
 * Hard gates from the blueprint:
 *   1. Company must have at least one branch.
 *   2. Company must have at least one assigned device.
 * Both checks happen here in the Action so any caller (UI, API,
 * test script) is forced through the same guarantee.
 *
 * Flow:
 *   1. Validate the gates.
 *   2. Create the pos_users row with user_type=Merchant,
 *      status=Inactive (becomes Active once setup completes),
 *      password=NULL (forces setup link usage).
 *   3. Generate a 64-char random token, hash it with SHA-256, store
 *      ONLY the hash + a 7-day expiry on the user row.
 *   4. Stamp invited_at + invited_by_admin_id.
 *   5. Mail the welcome message with the raw token embedded in the
 *      link.
 *   6. Audit-log the invitation.
 *
 * Everything runs inside a DB transaction so an email-send failure
 * rolls back the user creation (we don't want orphaned invites).
 */
final readonly class InvitePortalUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated payload from InvitePortalUserRequest
     */
    public function handle(Company $company, array $attributes, ?User $actor = null): User
    {
        return DB::transaction(function () use ($company, $attributes, $actor): User {
            // --- 1. Hard gates from blueprint §4.5 -----------------
            // Both branches and devices must exist before a portal
            // user can be invited. Server-side enforcement matches
            // the client-side "+ Invite" button being disabled in
            // the same condition.
            if (! $company->branches()->exists()) {
                throw new RuntimeException(
                    'Cannot invite a portal user before the company has at least one branch.',
                );
            }
            if (! $company->devices()->exists()) {
                throw new RuntimeException(
                    'Cannot invite a portal user before the company has at least one assigned device.',
                );
            }

            // --- 2. Generate raw + hashed setup token --------------
            // Str::random uses a cryptographically secure RNG.
            // SHA-256 hashing matches what the eventual /setup-password
            // endpoint will use to look the row up by token.
            $rawToken = Str::random(64);
            $hashedToken = hash('sha256', $rawToken);
            // 7-day window is generous enough for someone in another
            // time zone or on holiday but short enough that a stolen
            // mailbox archive expires quickly.
            $expiresAt = now()->addDays(7);

            // --- 3. Create the portal user row ---------------------
            /** @var User $portalUser */
            $portalUser = User::query()->create([
                'company_id' => $company->id,
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'] ?? null,
                // No password yet — set during setup-password.
                // Storing the auth-key hash of an empty string would
                // be a weak credential; NULL is cleaner.
                'password' => null,
                'user_type' => UserType::Merchant,
                // Inactive until setup completes so the merchant
                // portal's auth guard refuses login attempts on
                // half-provisioned accounts.
                'status' => UserStatus::Inactive,
                'branch_scope_json' => $attributes['branch_scope'] ?? null,
                'setup_token_hash' => $hashedToken,
                'setup_token_expires_at' => $expiresAt,
                'invited_at' => now(),
                'invited_by_admin_id' => $actor?->id,
            ]);

            // --- 4. Dispatch the welcome email ---------------------
            // Mail::to(...)->send(...) runs synchronously in dev
            // (MAIL_MAILER=log writes to storage/logs/laravel.log).
            // Production should switch to a queued mailer once Sentry
            // is wired up — failures here would currently roll back
            // the whole transaction, which is the safe default.
            Mail::to($portalUser->email)->send(
                new MerchantPortalWelcomeMail($portalUser, $company, $rawToken, $expiresAt),
            );

            // --- 5. Audit log --------------------------------------
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.invited',
                actorUserId: $actor?->id,
                companyId: $company->id,
                auditableType: User::class,
                auditableId: $portalUser->id,
                newValues: $portalUser->only([
                    'name', 'email', 'status', 'branch_scope_json', 'invited_at',
                ]),
            ));

            return $portalUser;
        });
    }
}
