<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projection of a merchant portal user for the Admin Portal's
 * "Portal Users" tab on the merchant detail page.
 *
 * Never included: password, any token or token hash, remember_token.
 *
 * Derived fields (LAUNCH-P1 P1-2):
 *   - `password_set`  : the user has chosen a password.
 *   - `setup_pending` : not yet — they still need their set-password
 *                       link; drives "Resend set-password link".
 *   - `set_password_link_expires_at` : expiry of the newest unused link
 *                       (the link itself is only ever shown once).
 *
 * @mixin User
 */
class PortalUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $openLink = PasswordResetToken::query()
            ->where('user_id', $this->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'user_type' => $this->user_type?->value,
            'status' => $this->status?->value,
            // null = all branches; array of ids = restricted scope.
            'branch_scope' => $this->branch_scope_json,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'invited_at' => $this->invited_at?->toIso8601String(),
            'invited_by_admin_id' => $this->invited_by_admin_id,
            'password_set' => $this->password !== null,
            'setup_pending' => $this->password === null,
            'set_password_link_expires_at' => $openLink?->expires_at?->toIso8601String(),
            'set_password_link_purpose' => $openLink?->purpose,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
