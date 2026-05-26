<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generate a fresh password for a merchant portal user.
 *
 * Replaces the old "resend invite" flow which only made sense when
 * users were created via email link. With the create-by-password
 * flow, the admin sometimes needs to mint a new password — for a
 * user who lost theirs, or for a security rotation.
 *
 * Refuses on:
 *   - Non-merchant user (e.g. a platform admin id arrived via the
 *     same route). 404 in the controller.
 *
 * Audit event: `portal_user.password_reset`. Like the create flow,
 * the password itself is NOT logged.
 */
final readonly class ResetMerchantUserPasswordAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @return array{user: User, plaintext_password: string}
     */
    public function handle(User $user, ?User $actor = null): array
    {
        return DB::transaction(function () use ($user, $actor): array {
            // Defensive: this endpoint should only be reached via
            // the /merchants/{uuid}/portal-users/{user}/reset-password
            // route which scope-binds the user to the merchant. But
            // double-check user_type so a misrouted request can't
            // rotate a platform admin's password through here.
            if ($user->user_type !== UserType::Merchant) {
                throw new RuntimeException(
                    'Cannot reset password — this user is not a merchant portal user.',
                );
            }

            $plaintextPassword = Str::password(
                length: 20,
                letters: true,
                numbers: true,
                symbols: false,
                spaces: false,
            );

            $user->password = $plaintextPassword; // bcrypted via cast
            $user->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.password_reset',
                actorUserId: $actor?->id,
                companyId: $user->company_id,
                auditableType: User::class,
                auditableId: $user->id,
                // No password material in the audit log — just the
                // fact that a reset happened.
                newValues: [
                    'reset_at' => now()->toIso8601String(),
                ],
            ));

            return [
                'user' => $user,
                'plaintext_password' => $plaintextPassword,
            ];
        });
    }
}
