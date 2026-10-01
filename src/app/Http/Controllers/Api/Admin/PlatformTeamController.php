<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\InvitePlatformUserAction;
use App\Actions\Admin\ReactivatePlatformUserAction;
use App\Actions\Admin\Role\AssignRolesToUserAction;
use App\Actions\Admin\SuspendPlatformUserAction;
use App\Actions\Admin\UpdatePlatformUserAction;
use App\Actions\Auth\ResetPasswordWithLinkAction;
use App\Actions\Auth\ResetTwoFactorAction;
use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InvitePlatformUserRequest;
use App\Http\Requests\Admin\UpdatePlatformUserRequest;
use App\Http\Resources\Admin\PlatformUserResource;
use App\Models\User;
use App\Policies\PortalUserPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Admin endpoints for the Platform Team page.
 *
 *   GET    /admin/api/v1/platform-team               → list admins
 *   POST   /admin/api/v1/platform-team               → invite a new admin
 *   PATCH  /admin/api/v1/platform-team/{user}        → update name/phone/role
 *   POST   /admin/api/v1/platform-team/{user}/suspend
 *   POST   /admin/api/v1/platform-team/{user}/reactivate
 *
 * Why no Policy class:
 *   {@see PortalUserPolicy} is already registered against
 *   User::class in AuthServiceProvider — it owns the merchant-portal
 *   invite/suspend semantics. Adding a second policy for the same
 *   model would collide. Instead this controller authorizes directly
 *   via $request->user()->can(PlatformPermission::...), which is
 *   exactly what the policy would do anyway.
 *
 * Each mutation routes through an atomic Action so the audit log
 * entry + DB write are transactional. The Actions are injected so
 * they can be swapped in tests.
 */
class PlatformTeamController extends Controller
{
    public function __construct(
        private readonly InvitePlatformUserAction $invite,
        private readonly UpdatePlatformUserAction $update,
        private readonly SuspendPlatformUserAction $suspend,
        private readonly ReactivatePlatformUserAction $reactivate,
        private readonly AssignRolesToUserAction $assignRoles,
    ) {}

    /**
     * GET /admin/api/v1/platform-team
     *
     * Lists all PlatformAdmin users with their current role + status.
     * Merchant portal users are explicitly excluded by user_type so
     * the page doesn't accidentally surface merchant identities.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, PlatformPermission::PlatformUsersView);

        $query = User::query()
            ->where('user_type', UserType::PlatformAdmin->value)
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        return PlatformUserResource::collection(
            $query->paginate(min($request->integer('per_page', 25), 100)),
        );
    }

    /**
     * POST /admin/api/v1/platform-team
     *
     * LAUNCH-P1 P1-8: creates the admin WITHOUT a password, assigns the
     * role and returns a one-time `set_password_link` (72 h) for the
     * "Copy set-password link" button; it is also emailed when mail is
     * configured. No password is generated or shown.
     */
    public function store(InvitePlatformUserRequest $request): JsonResponse
    {
        $this->ensure($request, PlatformPermission::PlatformUsersInvite);

        $result = $this->invite->handle($request->validated(), $request->user());

        return response()->json([
            'data' => (new PlatformUserResource($result['user']))->resolve($request),
            'set_password_link' => $result['link']->toArray(),
        ], 201);
    }

    /**
     * POST /admin/api/v1/platform-team/{user}/set-password-link
     *
     * Resend the link to an admin without a password, or reset one who
     * has a password: the old password is blocked at once, that admin's
     * sessions end and a 60-minute link is issued. Same permission as
     * inviting.
     */
    public function setPasswordLink(Request $request, User $user, ResetPasswordWithLinkAction $resetWithLink): JsonResponse
    {
        $this->ensure($request, PlatformPermission::PlatformUsersInvite);
        $this->refuseIfNotPlatformAdmin($user);

        // Owner follow-up 2026-10-01: a reset blocks the old password at
        // once (sessions end too); only the new link works.
        $result = $resetWithLink->handle($user, $request->user());

        return response()->json([
            'data' => (new PlatformUserResource($result['user']))->resolve($request),
            'set_password_link' => $result['link']->toArray(),
        ]);
    }

    /**
     * POST /admin/api/v1/platform-team/{user}/reset-two-factor
     *
     * LAUNCH-P1 P1-15: a Super Admin clears another admin's lost
     * authenticator. The admin is signed out everywhere and must set up
     * a new authenticator at their next sign-in. Requires an explicit
     * confirmation flag and a written reason; audited.
     */
    public function resetTwoFactor(Request $request, User $user, ResetTwoFactorAction $resetTwoFactor): PlatformUserResource|JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        if (! $actor->hasRole(PlatformRole::SuperAdmin->value)) {
            abort(403, 'Only a Super Admin can reset another admin\'s two-step login.');
        }
        $this->refuseIfNotPlatformAdmin($user);

        $validated = $request->validate([
            'confirm' => ['accepted'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $updated = $resetTwoFactor->handle($user, $actor, (string) $validated['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return PlatformUserResource::make($updated);
    }

    /**
     * PATCH /admin/api/v1/platform-team/{user}
     *
     * Partial-update name / phone / role. Email is intentionally
     * not editable here — see UpdatePlatformUserAction docstring.
     */
    public function update(UpdatePlatformUserRequest $request, User $user): PlatformUserResource
    {
        $this->ensure($request, PlatformPermission::PlatformUsersUpdateRoles);
        $this->refuseIfNotPlatformAdmin($user);

        return PlatformUserResource::make(
            $this->update->handle($user, $request->validated(), $request->user()),
        );
    }

    /**
     * POST /admin/api/v1/platform-team/{user}/suspend
     *
     * Action refuses self-suspension with a RuntimeException; we
     * surface that as a 422 so the SPA can show the message
     * inline rather than a generic 500.
     */
    public function suspend(Request $request, User $user): PlatformUserResource|JsonResponse
    {
        $this->ensure($request, PlatformPermission::PlatformUsersSuspend);
        $this->refuseIfNotPlatformAdmin($user);

        try {
            $updated = $this->suspend->handle($user, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return PlatformUserResource::make($updated);
    }

    /**
     * POST /admin/api/v1/platform-team/{user}/reactivate
     */
    public function reactivate(Request $request, User $user): PlatformUserResource
    {
        $this->ensure($request, PlatformPermission::PlatformUsersSuspend);
        $this->refuseIfNotPlatformAdmin($user);

        return PlatformUserResource::make(
            $this->reactivate->handle($user, $request->user()),
        );
    }

    /**
     * PATCH /admin/api/v1/platform-team/{user}/roles
     *
     * Phase 4.8b — replace the platform user's role list with
     * the requested set. Gated on the dedicated
     * platform_users.update_roles permission (NOT on
     * platform_users.update — role mutation is a meta-control
     * that shouldn't be inferable from "can edit the user's
     * name").
     *
     * Validation accepts any string array; the Action layer
     * resolves them against the platform team's role catalog
     * and silently drops unknown names.
     */
    public function assignRoles(Request $request, User $user): PlatformUserResource|JsonResponse
    {
        $this->ensure($request, PlatformPermission::PlatformUsersUpdateRoles);
        $this->refuseIfNotPlatformAdmin($user);

        $validated = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'max:64'],
        ]);

        try {
            $updated = $this->assignRoles->handle(
                $user,
                $validated['roles'],
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return PlatformUserResource::make($updated);
    }

    /**
     * Direct permission check (no Policy involved). Throws a
     * 403 AuthorizationException via abort() when the user lacks
     * the required permission.
     */
    private function ensure(Request $request, PlatformPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    /**
     * Refuse to operate on a merchant portal user via this endpoint
     * — the route resolves any user by id, so without this guard
     * an admin with PlatformUsersUpdateRoles could change a
     * merchant's role to a platform role. 404 keeps merchant ids
     * unenumerable from the platform team surface.
     */
    private function refuseIfNotPlatformAdmin(User $user): void
    {
        if ($user->user_type !== UserType::PlatformAdmin) {
            abort(404);
        }
    }
}
