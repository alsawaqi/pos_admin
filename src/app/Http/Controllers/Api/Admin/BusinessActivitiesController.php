<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateBusinessActivityAction;
use App\Actions\Admin\DeleteBusinessActivityAction;
use App\Actions\Admin\UpdateBusinessActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBusinessActivityRequest;
use App\Http\Requests\Admin\UpdateBusinessActivityRequest;
use App\Http\Resources\Admin\BusinessActivityResource;
use App\Models\BusinessActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Admin endpoints for the platform-wide list of business activities.
 *
 * The merchant onboarding wizard hits `index` (filtered to active
 * rows). The Settings → Business Activities admin page hits the
 * full CRUD set: store, update, destroy.
 *
 * Routes registered in routes/admin.php under
 * /admin/api/v1/business-activities.
 */
class BusinessActivitiesController extends Controller
{
    public function __construct(
        private readonly CreateBusinessActivityAction $createActivity,
        private readonly UpdateBusinessActivityAction $updateActivity,
        private readonly DeleteBusinessActivityAction $deleteActivity,
    ) {}

    /**
     * GET /admin/api/v1/business-activities
     *
     * Returns the active list by default (used by the merchant
     * create wizard). The admin Settings page passes `include_inactive=1`
     * to see deactivated rows for management.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // viewAny is always allowed by the policy — the merchant
        // wizard needs this list and shouldn't fail if the user
        // lacks BusinessActivitiesManage.
        $this->authorize('viewAny', BusinessActivity::class);

        $query = BusinessActivity::query();

        // Default to active-only. The Settings admin page sends
        // `include_inactive=1` so it can show every row.
        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->value());
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($q) use ($term): void {
                $q->where('name_en', 'like', "%{$term}%")
                    ->orWhere('name_ar', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%");
            });
        }

        return BusinessActivityResource::collection(
            $query
                ->orderBy('category')
                ->orderBy('display_order')
                ->orderBy('name_en')
                ->get(),
        );
    }

    /**
     * POST /admin/api/v1/business-activities
     *
     * Add a new entry to the platform-wide activity catalogue.
     */
    public function store(StoreBusinessActivityRequest $request): JsonResponse
    {
        $this->authorize('create', BusinessActivity::class);

        $activity = $this->createActivity->handle($request->validated(), $request->user());

        return BusinessActivityResource::make($activity)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /admin/api/v1/business-activities/{activity}
     *
     * Edit any subset of the row's fields. Toggling `is_active=false`
     * hides the activity from new merchant wizards while leaving
     * existing merchants' attachments intact.
     */
    public function update(UpdateBusinessActivityRequest $request, BusinessActivity $activity): BusinessActivityResource
    {
        $this->authorize('update', $activity);

        $updated = $this->updateActivity->handle($activity, $request->validated(), $request->user());

        return BusinessActivityResource::make($updated);
    }

    /**
     * DELETE /admin/api/v1/business-activities/{activity}
     *
     * Hard-delete. Refuses with 409 Conflict if the activity is
     * still attached to one or more merchants — the admin should
     * deactivate it via PATCH instead.
     */
    public function destroy(Request $request, BusinessActivity $activity): JsonResponse
    {
        $this->authorize('delete', $activity);

        try {
            $this->deleteActivity->handle($activity, $request->user());
        } catch (RuntimeException $e) {
            // Surfaces the "still in use" guard from the action as
            // a structured 409 the front-end can render nicely.
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(null, 204);
    }
}
