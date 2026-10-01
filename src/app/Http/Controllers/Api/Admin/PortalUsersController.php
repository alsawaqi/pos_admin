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
 * LAUNCH-P1 P1-2 (owner decision: no hand-given passwords): creating a
 * login and "reset password" both issue a single-use set-password link
 * (invite 72 h, reset 60 min). The link is emailed when mail is
 * configured and returned ONCE as `set_password_link` so the admin can
 * copy it ("Copy set-password link", e.g. WhatsApp). No plaintext
 * password exists anywhere any more.
 *
 * The endpoints:
 *   GET    /                              — list portal users
 *   POST   /                              — create a login (+ link)
 *   PATCH  /{user}                        — change status / scope / phone
 *   POST   /{user}/reset-password         — (re)send a set-password link
 *
 * Permissions (PortalUserPolicy): viewAny/view → MerchantUsersView,
 * create/reset → MerchantUsersInvite, update → MerchantUsersRevoke.
 *
 * Tenant scope: the portal user must belong to the URL-bound company.
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
     * Create a merchant login with NO password and return the user plus
     * a one-time `set_password_link` (url, expires_at, emailed...).
     */
    public function store(CreateMerchantUserRequest $request, Company $merchant): JsonResponse
    {
        $this->authorize('invite', User::class);

        $result = $this->createMerchantUser->handle(
            $merchant,
            $request->validated(),
            $request->user(),
        );

        return response()->json([
            'data' => (new PortalUserResource($result['user']))->resolve($request),
            'set_password_link' => $result['link']->toArray(),
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
     * Send a set-password link: a resent invite (72 h) when the user
     * never chose a password, otherwise an admin reset (60 min) that
     * also ends the user's open sessions.
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
            'set_password_link' => $result['link']->toArray(),
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
