<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Apply admin-side updates to an existing merchant portal user.
 * Three things the admin can change:
 *
 *   - branch_scope: restrict the user to specific branches (or
 *     clear back to "all branches" by passing null).
 *   - status: suspend (locks them out of the merchant portal) or
 *     reactivate (re-enables a previously-suspended account).
 *   - phone: support staff often update contact details on the
 *     user's behalf.
 *
 * Each change writes a before/after audit log entry so support can
 * reconstruct "who restricted this user's branches and when".
 */
final readonly class UpdatePortalUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated payload
     */
    public function handle(Company $company, User $portalUser, array $attributes, ?User $actor = null): User
    {
        return DB::transaction(function () use ($company, $portalUser, $attributes, $actor): User {
            // Defense in depth — the route binding already proves
            // the user is under this company, but explicit beats
            // implicit when the audit trail is involved.
            if ($portalUser->company_id !== $company->id) {
                throw new RuntimeException(
                    'Portal user does not belong to the specified company.',
                );
            }

            $before = $portalUser->only(['status', 'branch_scope_json', 'phone']);

            // Only fields actually present in the payload get
            // touched — the FormRequest uses `sometimes` so absent
            // fields stay as they are.
            if (array_key_exists('status', $attributes)) {
                $portalUser->status = UserStatus::from($attributes['status']);
            }
            if (array_key_exists('branch_scope', $attributes)) {
                // The wire format uses `branch_scope` (without
                // _json suffix) — the cast on the model handles the
                // array <-> JSON conversion.
                $portalUser->branch_scope_json = $attributes['branch_scope'];
            }
            if (array_key_exists('phone', $attributes)) {
                $portalUser->phone = $attributes['phone'];
            }

            if (! $portalUser->isDirty()) {
                // Nothing changed — skip the DB write + audit row.
                return $portalUser;
            }

            $portalUser->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.updated',
                actorUserId: $actor?->id,
                companyId: $company->id,
                auditableType: User::class,
                auditableId: $portalUser->id,
                oldValues: $before,
                newValues: $portalUser->only(array_keys($before)),
            ));

            return $portalUser->refresh();
        });
    }
}
