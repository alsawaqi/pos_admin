<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Auth\IssueSetPasswordLinkAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\Auth\SetPasswordLink;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Invite a new platform admin to the team — LAUNCH-P1 P1-8.
 *
 * The admin is created WITHOUT a password and receives a single-use
 * set-password link (72 hours) for the admin portal's /set-password
 * page: emailed when mail is configured, and returned once so the
 * inviting admin can copy it. Nobody ever sees the new admin's
 * password. After choosing it, the new admin signs in and is forced to
 * set up authenticator 2FA before anything else (P1-15).
 *
 * Spatie roles are team-scoped; platform roles live under
 * {@see TenantContext::PLATFORM_TEAM_ID}, so the registrar is switched
 * around assignRole().
 *
 * Audit: `platform_user.invited` + `platform_user.set_password_link_issued`.
 */
final readonly class InvitePlatformUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private IssueSetPasswordLinkAction $issueLink,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null, role: string}  $attributes
     * @return array{user: User, link: SetPasswordLink}
     */
    public function handle(array $attributes, User $actor): array
    {
        $user = DB::transaction(function () use ($attributes, $actor): User {
            /** @var User $user */
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'] ?? null,
                // No password until the invitee uses the link.
                'password' => null,
                'user_type' => UserType::PlatformAdmin,
                'status' => UserStatus::Active,
                'invited_at' => now(),
                'invited_by_admin_id' => $actor->id,
            ]);

            $previousTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();
            app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
            try {
                $user->assignRole($attributes['role']);
            } finally {
                app(PermissionRegistrar::class)->setPermissionsTeamId($previousTeamId);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'platform_user.invited',
                actorUserId: $actor->id,
                auditableType: User::class,
                auditableId: $user->id,
                newValues: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $attributes['role'],
                    'status' => $user->status?->value,
                    'user_type' => $user->user_type?->value,
                    'password' => 'set_password_link',
                ],
            ));

            return $user;
        });

        $link = $this->issueLink->handle($user, PasswordResetToken::PURPOSE_INVITE, $actor);

        return ['user' => $user, 'link' => $link];
    }

    /**
     * Convenience for tests + seeders — accepts a PlatformRole enum
     * instead of the raw string. Same as handle() otherwise.
     *
     * @param  array{name: string, email: string, phone?: string|null}  $attributes
     * @return array{user: User, link: SetPasswordLink}
     */
    public function handleWithRole(array $attributes, PlatformRole $role, User $actor): array
    {
        return $this->handle([...$attributes, 'role' => $role->value], $actor);
    }
}
