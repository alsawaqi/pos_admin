<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projection of a merchant portal user for the Admin Portal's
 * "Portal Users" tab on the merchant detail page.
 *
 * Notes on what is INTENTIONALLY omitted:
 *   - password (always hidden via the User model's #[Hidden])
 *   - setup_token_hash (sensitive — never leaks over the wire)
 *   - remember_token (Laravel internal)
 *
 * Notes on derived/convenience fields:
 *   - `setup_pending`: true when the user has not yet completed
 *     setup (no password, token still un-redeemed). Drives the UI
 *     to show the "Resend invite" button vs the "Suspend" button.
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
        $setupPending = $this->password === null && $this->setup_token_hash !== null;

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
            'setup_pending' => $setupPending,
            // Token expiry surfaces in the UI so support can warn
            // the recipient that the link is about to die.
            'setup_token_expires_at' => $this->setup_token_expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
