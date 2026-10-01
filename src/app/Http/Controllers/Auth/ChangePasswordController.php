<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in admin changes their own password (LAUNCH-P1 P1-8).
 *
 * The current password is required. Saving a new password rotates
 * auth_version (User::booted), which ends every OTHER session of this
 * admin; this session is kept by regenerating its id and storing the new
 * version. Audited (`platform_user.password_changed`).
 */
class ChangePasswordController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function update(ChangePasswordRequest $request, WriteAuditLogAction $writeAuditLog): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        if (! Hash::check((string) $validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is not correct.'],
            ]);
        }

        $user->forceFill([
            'password' => (string) $validated['password'], // hashed by the model cast
        ])->save();

        $request->session()->regenerate();
        $request->session()->put('pos.auth_version', (int) $user->auth_version);

        $writeAuditLog->handle(new AuditLogData(
            event: 'platform_user.password_changed',
            actorUserId: (int) $user->id,
            auditableType: User::class,
            auditableId: (int) $user->id,
            newValues: [
                'changed_at' => now()->toIso8601String(),
                'other_sessions_ended' => true,
            ],
        ));

        return response()->json([
            'message' => 'Password changed. Your other sessions were signed out.',
        ]);
    }
}
