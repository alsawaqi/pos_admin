<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Flip a platform admin to status=Suspended (cannot log in).
 *
 * Two safety rails:
 *   - cannot self-suspend (would lock the actor out of the page
 *     they're acting on, which is almost always a mistake)
 *   - no-op when already suspended (idempotent; the controller can
 *     call this without first reading current state)
 *
 * Reactivation is its own action ({@see ReactivatePlatformUserAction})
 * to keep the audit trail crisp — one event per state change.
 *
 * Audit event: `platform_user.suspended`.
 */
final readonly class SuspendPlatformUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(User $user, User $actor): User
    {
        return DB::transaction(function () use ($user, $actor): User {
            if ($user->id === $actor->id) {
                throw new RuntimeException(
                    'You cannot suspend your own account.',
                );
            }

            if ($user->status === UserStatus::Suspended) {
                // Already in the target state — nothing to do.
                // Return the row without writing a redundant audit
                // entry. Idempotent so the SPA can re-issue the
                // request without producing duplicate events.
                return $user;
            }

            $previous = $user->status?->value;
            $user->status = UserStatus::Suspended;
            $user->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'platform_user.suspended',
                actorUserId: $actor->id,
                auditableType: User::class,
                auditableId: $user->id,
                oldValues: ['status' => $previous],
                newValues: ['status' => $user->status->value],
            ));

            return $user;
        });
    }
}
