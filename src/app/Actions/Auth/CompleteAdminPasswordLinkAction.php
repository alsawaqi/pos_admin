<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Consume an admin's set-password link and store the chosen password
 * (LAUNCH-P1 P1-8): an invite, an admin-issued reset or a forgot-password
 * link.
 *
 * Every failure (unknown email, merchant row, inactive admin, wrong,
 * expired or used token) gives the SAME message, so the public endpoint
 * reveals nothing. On success:
 *  - the password is stored (bcrypt via the model cast);
 *  - auth_version rotates (User::booted on a password change), ending
 *    every existing session of that admin;
 *  - this token is marked used and any other unused one is deleted;
 *  - an audit row records which kind of link was used.
 */
final readonly class CompleteAdminPasswordLinkAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(string $email, string $rawToken, string $password): User
    {
        $user = User::query()
            ->platformAdmin()
            ->where('email', Str::lower($email))
            ->where('status', 'active')
            ->first();

        $token = $user === null ? null : PasswordResetToken::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $rawToken))
            ->whereNull('used_at')
            ->first();

        if ($user === null || $token === null || $token->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'token' => ['This link is invalid or has expired. Ask for a new one.'],
            ]);
        }

        DB::transaction(function () use ($user, $token, $password): void {
            // Atomic single use (review finding): claim the token with a
            // conditional UPDATE first. Of two requests racing with the
            // same link, exactly one changes the row; the other gets the
            // same "invalid or expired" answer and changes nothing.
            $claimed = PasswordResetToken::query()
                ->whereKey($token->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['used_at' => now()]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages([
                    'token' => ['This link is invalid or has expired. Ask for a new one.'],
                ]);
            }

            $user->forceFill([
                'password' => $password, // hashed by the model cast
                'remember_token' => null,
            ])->save();

            PasswordResetToken::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'platform_user.password_set',
                actorUserId: (int) $user->id,
                auditableType: User::class,
                auditableId: (int) $user->id,
                newValues: [
                    'set_at' => now()->toIso8601String(),
                    'via' => $token->purpose,
                ],
            ));
        });

        return $user;
    }
}
