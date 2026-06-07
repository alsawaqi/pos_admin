<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Invite a new platform admin to the team.
 *
 * Trade-off versus the merchant-portal invite flow
 * ({@see InvitePortalUserAction}): we deliberately do NOT use the
 * email/setup-link mechanism here because there is no
 * `/admin/setup-password` page in pos_admin yet — building that
 * would be a meaningful side-quest. Instead we generate a strong
 * random password, persist its bcrypt hash via the User model's
 * `password` cast, and return the plaintext to the inviting admin
 * exactly once. The admin shares it with the invitee out of band
 * (Signal, chat, in person) and the invitee changes it on first
 * login via the profile page.
 *
 * If/when this becomes a recurring problem (lots of forgotten-pw
 * resets, leaked passwords in chat history), swap to the
 * setup-token mechanism by mirroring InvitePortalUserAction and
 * building the setup-password route in pos_admin.
 *
 * Permissions side:
 *   Spatie's "teams" feature scopes role assignments by team_id.
 *   Platform-side roles all live under
 *   {@see TenantContext::PLATFORM_TEAM_ID} so we have to switch
 *   the registrar's team id before calling assignRole() — without
 *   it the role row would be written with team_id = (the actor's
 *   current tenant) which makes the role invisible at login.
 *
 * Whole thing runs in a transaction so a failed audit-log or role
 * assignment rolls the user creation back.
 *
 * Audit event: `platform_user.invited`.
 */
final readonly class InvitePlatformUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null, role: string}  $attributes
     * @return array{user: User, plaintext_password: string}
     */
    public function handle(array $attributes, User $actor): array
    {
        return DB::transaction(function () use ($attributes, $actor): array {
            // 20-char alphanumeric password — ~120 bits of entropy,
            // way more than needed but copy/paste-safe (no symbols
            // that would need escaping in a chat message or terminal).
            // The User model's `password` cast bcrypts this on save.
            $plaintextPassword = Str::password(
                length: 20,
                letters: true,
                numbers: true,
                symbols: false,
                spaces: false,
            );

            /** @var User $user */
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'] ?? null,
                // The 'hashed' cast on User::password bcrypts on save.
                'password' => $plaintextPassword,
                'user_type' => UserType::PlatformAdmin,
                // Active immediately — the admin already has the
                // password to log in. Suspension is a separate
                // action (SuspendPlatformUserAction).
                'status' => UserStatus::Active,
                'invited_at' => now(),
                'invited_by_admin_id' => $actor->id,
            ]);

            // Assign the chosen role under the platform team scope.
            // We restore the prior team id in the finally so the
            // rest of the request (which may belong to a tenant
            // context) stays consistent.
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
                // Capture name, email, role, status. Intentionally
                // NOT capturing the password (even its hash) — the
                // audit log is read-only by support staff and they
                // should never see credential material.
                newValues: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $attributes['role'],
                    'status' => $user->status?->value,
                    'user_type' => $user->user_type?->value,
                ],
            ));

            return [
                'user' => $user,
                // Returned to the controller, which surfaces it
                // ONCE on the response. Frontend shows a copy-to-
                // clipboard modal then forgets — the plaintext
                // never persists anywhere.
                'plaintext_password' => $plaintextPassword,
            ];
        });
    }

    /**
     * Convenience for tests + seeders — accepts a PlatformRole enum
     * instead of the raw string. Same as handle() otherwise.
     *
     * @param  array{name: string, email: string, phone?: string|null}  $attributes
     * @return array{user: User, plaintext_password: string}
     */
    public function handleWithRole(array $attributes, PlatformRole $role, User $actor): array
    {
        return $this->handle([...$attributes, 'role' => $role->value], $actor);
    }
}
