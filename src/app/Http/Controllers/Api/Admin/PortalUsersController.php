<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateMerchantUserAction;
use App\Actions\Admin\ResetMerchantUserPasswordAction;
use App\Actions\Admin\UpdatePortalUserAction;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateMerchantUserRequest;
use App\Http\Requests\Admin\UpdatePortalUserRequest;
use App\Http\Resources\Admin\PortalUserResource;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Manage merchant portal users for a given merchant
 * (blueprint §4.5). Routes are nested under
 * /admin/api/v1/merchants/{merchant:uuid}/portal-users.
 *
 * Flow changed from "invite by email" to "create with password":
 * the platform admin enters name + email, the action generates a
 * 20-char random password, the response carries the plaintext
 * ONCE, the admin shares it with the merchant out of band. The
 * merchant logs into pos_merchant with email + that password and
 * changes it from their profile page once that surface is built.
 *
 * The endpoints:
 *   GET    /                              — list portal users
 *   POST   /                              — create the initial admin
 *   PATCH  /{user}                        — change status / scope / phone
 *   POST   /{user}/reset-password         — mint a new password
 *
 * Permissions:
 *   - viewAny / view  → MerchantUsersView
 *   - create / reset  → MerchantUsersInvite (semantic still
 *                       "you can provision portal access")
 *   - update          → MerchantUsersRevoke
 * Enforced by {@see \App\Policies\PortalUserPolicy}.
 *
 * Tenant scope: the portal user must belong to the URL-bound
 * company. Each endpoint guards against admins mixing up portal
 * users from different merchants; the Action layer enforces the
 * same check with an explicit company_id comparison for defence
 * in depth.
 */
class PortalUsersController extends Controller
{
    public function __construct(
        private readonly CreateMerchantUserAction $createMerchantUser,
        private readonly ResetMerchantUserPasswordAction $resetPassword,
        private readonly UpdatePortalUserAction $updatePortalUser,
    ) {}

    /**
     * GET /merchants/{merchant}/portal-users
     */
    public function index(Company $merchant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->where('company_id', $merchant->id)
            ->where('user_type', UserType::Merchant)
            ->orderByDesc('created_at')
            ->get();

        return PortalUserResource::collection($users);
    }

    /**
     * POST /merchants/{merchant}/portal-users
     *
     * Create the initial admin user. Action enforces the blueprint's
     * "≥1 branch + ≥1 device" gate; both must be present before the
     * first user can be provisioned. Returns the user payload PLUS
     * a one-shot `plaintext_password` field — the SPA surfaces that
     * in a copy-once modal then forgets it. Subsequent reads of
     * this user via GET .../portal-users omit the password.
     */
    public function store(CreateMerchantUserRequest $request, Company $merchant): JsonResponse
    {
        $this->authorize('invite', User::class);

        try {
            $result = $this->createMerchantUser->handle(
                $merchant,
                $request->validated(),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            // 422 with a plain message the UI shows verbatim — used
            // for the "no branches" / "no devices" gate violations.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new PortalUserResource($result['user']))->resolve($request),
            'plaintext_password' => $result['plaintext_password'],
        ], 201);
    }

    /**
     * PATCH /merchants/{merchant}/portal-users/{user}
     *
     * Update branch scope, status (suspend / reactivate), or phone.
     */
    public function update(UpdatePortalUserRequest $request, Company $merchant, User $portalUser): PortalUserResource
    {
        $this->authorize('update', $portalUser);

        $this->ensureSameTenant($merchant, $portalUser);

        $updated = $this->updatePortalUser->handle(
            $merchant,
            $portalUser,
            $request->validated(),
            $request->user(),
        );

        return PortalUserResource::make($updated);
    }

    /**
     * POST /merchants/{merchant}/portal-users/{user}/reset-password
     *
     * Replaces the old "resend invite" endpoint. Mints a fresh
     * 20-char password, returns plaintext ONCE so the admin can
     * share it with the merchant. Same modal pattern as create.
     */
    public function resetPassword(Company $merchant, User $portalUser): JsonResponse
    {
        $this->authorize('invite', User::class);

        $this->ensureSameTenant($merchant, $portalUser);

        try {
            $result = $this->resetPassword->handle($portalUser, request()->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new PortalUserResource($result['user']))->resolve(request()),
            'plaintext_password' => $result['plaintext_password'],
        ]);
    }

    /**
     * Guard against a routing mismatch where /merchants/{A}/portal-users/{B's user}
     * would surface or mutate the wrong tenant's row. The route
     * binding doesn't enforce this cross-link on its own.
     */
    private function ensureSameTenant(Company $merchant, User $portalUser): void
    {
        if ($portalUser->company_id !== $merchant->id) {
            abort(404);
        }
    }
}
