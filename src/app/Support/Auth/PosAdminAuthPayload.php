<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use BackedEnum;
use Illuminate\Http\Request;

class PosAdminAuthPayload
{
    /**
     * @return array{id: int|string|null, name: string|null, email: string|null, user_type: string|null, status: string|null, roles: list<string>, permissions: list<string>}
     */
    public function user(User $user): array
    {
        $userType = $user->getAttribute('user_type');
        $status = $user->getAttribute('status');

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => $this->enumValue($userType),
            'status' => $this->enumValue($status),
            // Phase D8 — the SPA's Security page reads this to
            // render the 2FA card's enabled/disabled state.
            'two_factor_enabled' => $user->hasConfirmedTwoFactor(),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }

    /**
     * @return array{remembered: bool, idle_timeout_seconds: int, last_activity_at: int|null}
     */
    public function session(Request $request): array
    {
        return [
            'remembered' => (bool) $request->session()->get('pos_admin.remembered', false),
            'idle_timeout_seconds' => ((int) config('pos_admin_auth.session.idle_timeout_minutes')) * 60,
            'last_activity_at' => $request->session()->get('pos_admin.last_activity_at'),
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }
}
