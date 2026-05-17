<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserType;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant from the authenticated user.
 *
 * Merchant users carry their company_id and trigger automatic query scoping
 * via {@see \App\Models\Concerns\BelongsToCompany}. Platform admins are not
 * scoped; controllers that act on a specific company must explicitly call
 * {@see TenantContext::run()} for cross-tenant safety.
 */
class SetTenantContext
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->user_type === UserType::Merchant && $user->company_id !== null) {
            $this->tenantContext->set((int) $user->company_id);
        } else {
            $this->tenantContext->forget();
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantContext->permissionTeamId());

        return $next($request);
    }
}
