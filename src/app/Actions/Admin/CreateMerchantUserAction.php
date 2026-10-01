<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Auth\IssueSetPasswordLinkAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Company;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\Auth\SetPasswordLink;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create a merchant portal login (blueprint §4.5) — LAUNCH-P1 P1-2 /
 * P1-14.
 *
 * Owner decision 2026-09-29 1a: no hand-given passwords. The user is
 * created WITHOUT a password (NULL — nobody can sign in with it) and
 * receives a single-use set-password link valid for 72 hours, emailed
 * when mail is configured and always returned once so the admin can
 * copy it ("Copy set-password link", e.g. for WhatsApp). The merchant
 * chooses their own password on the merchant portal's /setup-password
 * page, so must_change_password stays false.
 *
 * P1-14: there is no longer a "merchant must already have a branch and
 * a device" gate. Neither is a technical dependency of the portal: the
 * first login is unscoped (branch_scope_json NULL = every branch, so no
 * branch id is needed), the catalogue is company-wide, and devices are
 * only used by the tills. The owner's onboarding order gives the login
 * (step 2) before branches (step 4) and devices (step 5).
 *
 * The first user becomes merchant_super_admin (team = company id).
 *
 * Audit: `portal_user.created` here, plus
 * `portal_user.set_password_link_issued` from the link action.
 */
final readonly class CreateMerchantUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private IssueSetPasswordLinkAction $issueLink,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null}  $attributes
     * @return array{user: User, link: SetPasswordLink}
     */
    public function handle(Company $company, array $attributes, ?User $actor = null): array
    {
        $user = DB::transaction(function () use ($company, $attributes, $actor): User {
            /** @var User $user */
            $user = User::query()->create([
                'company_id' => $company->id,
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'] ?? null,
                // No password until the merchant uses the link
                // (must_change_password keeps its false default: the
                // merchant picks the password themselves).
                'password' => null,
                'user_type' => UserType::Merchant,
                'status' => UserStatus::Active,
                // NULL = access to every branch of this merchant.
                'branch_scope_json' => null,
                'setup_token_hash' => null,
                'setup_token_expires_at' => null,
                'invited_at' => now(),
                'invited_by_admin_id' => $actor?->id,
            ]);

            // Spatie roles are team-scoped by company id; switch the
            // registrar so the owner role lands under the merchant.
            $registrar = app(PermissionRegistrar::class);
            $previousTeam = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($company->id);
            try {
                $role = Role::query()->firstOrCreate([
                    'name' => 'merchant_super_admin',
                    'guard_name' => 'web',
                    'team_id' => $company->id,
                ]);
                $user->assignRole($role);
            } finally {
                $registrar->setPermissionsTeamId($previousTeam);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.created',
                actorUserId: $actor?->id,
                companyId: $company->id,
                auditableType: User::class,
                auditableId: $user->id,
                newValues: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status?->value,
                    'user_type' => $user->user_type?->value,
                    'branch_scope' => 'all',
                    'role' => 'merchant_super_admin',
                    'password' => 'set_password_link',
                ],
            ));

            return $user;
        });

        // After commit: the email must never point at an account that a
        // rollback removed.
        $link = $this->issueLink->handle($user, PasswordResetToken::PURPOSE_INVITE, $actor);

        return ['user' => $user, 'link' => $link];
    }
}
