<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the initial merchant portal admin user (blueprint §4.5).
 *
 * Flow change from the original invite-by-email flow:
 *   - No email is sent. The platform admin generates a password
 *     here and shares the credentials with the merchant out of
 *     band (Signal, chat, in person).
 *   - The user is created with status=Active and a bcrypt-hashed
 *     password (NOT NULL). They can log into pos_merchant
 *     immediately with email + the plaintext password returned
 *     ONCE from this action.
 *   - branch_scope_json is left NULL — the initial user has
 *     access to every branch of their merchant. They become the
 *     merchant "super admin" by virtue of being first, and can
 *     create scoped staff users themselves from inside the
 *     merchant portal once that surface is built.
 *
 * Hard gates from blueprint §4.5 stay in place:
 *   1. Company must have at least one branch.
 *   2. Company must have at least one assigned device.
 * Both checks happen here so any caller (UI, API, test script)
 * is forced through the same guarantee.
 *
 * Audit event: `portal_user.created`. (The old event
 * `portal_user.invited` is retained in {@see InvitePortalUserAction}
 * for back-compat with any existing audit-log rows; new actions
 * use the .created spelling.)
 *
 * @see \App\Actions\Admin\InvitePlatformUserAction the parallel
 *      flow for platform admins — same shape, different table.
 */
final readonly class CreateMerchantUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null}  $attributes
     * @return array{user: User, plaintext_password: string}
     */
    public function handle(Company $company, array $attributes, ?User $actor = null): array
    {
        return DB::transaction(function () use ($company, $attributes, $actor): array {
            // --- 1. Hard gates from blueprint §4.5 -----------------
            if (! $company->branches()->exists()) {
                throw new RuntimeException(
                    'Cannot create a portal user before the company has at least one branch.',
                );
            }
            if (! $company->devices()->exists()) {
                throw new RuntimeException(
                    'Cannot create a portal user before the company has at least one assigned device.',
                );
            }

            // --- 2. Generate the one-time password -----------------
            // 20-char alphanumeric. ~120 bits of entropy, way more
            // than required; symbols are off so the admin can paste
            // it into a chat / terminal without escaping issues. The
            // User model's `password` cast bcrypts on save.
            $plaintextPassword = Str::password(
                length: 20,
                letters: true,
                numbers: true,
                symbols: false,
                spaces: false,
            );

            /** @var User $user */
            $user = User::query()->create([
                'company_id' => $company->id,
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'] ?? null,
                'password' => $plaintextPassword,
                // Force a self-chosen password on first login -- the
                // admin-shared password is temporary. pos_merchant's
                // change-password flow clears this flag.
                'must_change_password' => true,
                'user_type' => UserType::Merchant,
                // Active immediately — the admin already has the
                // password to share. No setup-link flow needed.
                'status' => UserStatus::Active,
                // NULL = access to every branch of this merchant.
                // The first user is the implicit super admin; they
                // create scoped users from inside the merchant
                // portal later.
                'branch_scope_json' => null,
                // Setup-token columns stay NULL because there is
                // no setup link to validate against.
                'setup_token_hash' => null,
                'setup_token_expires_at' => null,
                // Reuse the existing audit columns to capture WHO
                // and WHEN the platform admin created the account.
                // Wording is now slightly off ("invited_at" → really
                // "created_at_by_admin") but the columns are useful
                // — leaving them as-is avoids a migration for
                // cosmetics.
                'invited_at' => now(),
                'invited_by_admin_id' => $actor?->id,
            ]);

            // --- 3. Assign merchant_super_admin role ----------------
            // The new user is the merchant's owner — full access.
            // Spatie permissions are team-scoped via the company
            // id, so we switch the registrar's team_id before
            // creating + assigning the role. Without that switch,
            // the role row would land under the platform team
            // (id=0) and be invisible to pos_merchant on login.
            //
            // The role name `merchant_super_admin` matches what
            // pos_merchant's MerchantRole enum exposes — same
            // string, two places. Future role keys added there
            // also seed lazily from pos_merchant's
            // SeedMerchantRolesAction when first used; pos_admin
            // only needs to make sure the owner role exists.
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

            // --- 4. Audit -------------------------------------------
            // Intentionally excluding the password (even its hash)
            // from new_values — credential material should never
            // leak into the audit log.
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
                    'branch_scope' => 'all', // explicit for the
                                              // audit reader
                    'role' => 'merchant_super_admin',
                ],
            ));

            return [
                'user' => $user,
                'plaintext_password' => $plaintextPassword,
            ];
        });
    }
}
