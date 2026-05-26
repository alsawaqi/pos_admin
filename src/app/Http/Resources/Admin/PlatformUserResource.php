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
 * Surfaces role + status alongside identity fields. The role
 * lookup goes through spatie's getRoleNames() under the platform
 * team scope — without that scope swap, spatie would return roles
 * for whatever tenant context the request is currently in
 * (typically empty, since the Team page is platform-wide).
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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status?->value,
            'user_type' => $this->user_type?->value,
            // Single role per platform admin (we don't model
            // multi-role admins). Pull from the platform team scope
            // explicitly so it doesn't accidentally read from a
            // tenant scope set by upstream middleware.
            'role' => $this->resolvePlatformRole(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'invited_at' => $this->invited_at?->toIso8601String(),
            'invited_by_admin_id' => $this->invited_by_admin_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function resolvePlatformRole(): ?string
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

        try {
            return $this->getRoleNames()->first();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }
}
