<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\PermissionRegistrar;

/**
 * Projection of a platform admin {@see User} for the Team page.
 *
 * Phase 4.8b change: `role` (single string) is replaced with
 * `roles` (array of strings) so an admin can hold any
 * combination of platform roles. Backward-compat `role` field
 * is also returned as the first entry for one release so an
 * older frontend continues to work.
 *
 * Role lookup explicitly switches the spatie team_id to the
 * platform sentinel before reading, otherwise spatie would
 * return roles for whatever tenant context the request is
 * currently in (typically empty for the platform Team page).
 *
 * @mixin User
 */
class PlatformUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $roles = $this->resolveRoles();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status?->value,
            'user_type' => $this->user_type?->value,
            // Backward-compat single role — first role or null.
            // Drop this in a follow-up release once nothing reads it.
            'role' => $roles[0] ?? null,
            'roles' => $roles,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'invited_at' => $this->invited_at?->toIso8601String(),
            'invited_by_admin_id' => $this->invited_by_admin_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveRoles(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

        try {
            return $this->getRoleNames()->values()->all();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }
}
