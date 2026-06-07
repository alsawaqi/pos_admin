<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Flip a platform admin back to status=Active.
 *
 * Idempotent — already-active users return without an audit write.
 * No "cannot reactivate yourself" guard because by definition you
 * can't reach this code path on yourself: a suspended user can't
 * log in to trigger it.
 *
 * Audit event: `platform_user.reactivated`.
 */
final readonly class ReactivatePlatformUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(User $user, User $actor): User
    {
        return DB::transaction(function () use ($user, $actor): User {
            if ($user->status === UserStatus::Active) {
                return $user;
            }

            $previous = $user->status?->value;
            $user->status = UserStatus::Active;
            $user->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'platform_user.reactivated',
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
