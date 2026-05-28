<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateDeviceMakeAction;
use App\Actions\Admin\DeleteDeviceMakeAction;
use App\Actions\Admin\UpdateDeviceMakeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDeviceMakeRequest;
use App\Http\Requests\Admin\UpdateDeviceMakeRequest;
use App\Http\Resources\Admin\DeviceMakeResource;
use App\Models\DeviceMake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Endpoints for the Device Makes catalogue (Settings → Device
 * catalogue, makes pane). Mirrors {@see BusinessActivitiesController}.
 *
 * Routes (admin.php):
 *   GET    /device-makes                — list (defaults to active)
 *   POST   /device-makes                — add
 *   PATCH  /device-makes/{make}         — edit
 *   DELETE /device-makes/{make}         — delete (refused with 409
 *                                          if any device or model
 *                                          still references it)
 *
 * The Register Device cascading dropdown calls the list endpoint to
 * populate the Make options; the admin catalogue page calls it with
 * `include_inactive=1` to see deactivated rows for management.
 */
class DeviceMakesController extends Controller
{
    public function __construct(
        private readonly CreateDeviceMakeAction $createMake,
        private readonly UpdateDeviceMakeAction $updateMake,
        private readonly DeleteDeviceMakeAction $deleteMake,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DeviceMake::class);

        $query = DeviceMake::query()
            ->withCount('models')
            ->withCount('devices');

        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where('name', 'like', "%{$term}%");
        }

        return DeviceMakeResource::collection(
            $query->orderBy('display_order')->orderBy('name')->get(),
        );
    }

    public function store(StoreDeviceMakeRequest $request): JsonResponse
    {
        $this->authorize('create', DeviceMake::class);

        $make = $this->createMake->handle($request->validated(), $request->user());

        return DeviceMakeResource::make($make)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateDeviceMakeRequest $request, DeviceMake $make): DeviceMakeResource
    {
        $this->authorize('update', $make);

        $updated = $this->updateMake->handle($make, $request->validated(), $request->user());

        return DeviceMakeResource::make($updated->loadCount(['models', 'devices']));
    }

    public function destroy(Request $request, DeviceMake $make): JsonResponse
    {
        $this->authorize('delete', $make);

        try {
            $this->deleteMake->handle($make, $request->user());
        } catch (RuntimeException $e) {
            // "still in use" / "still has models" guards land here
            // and get rendered verbatim by the UI as a friendly
            // banner above the table.
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(null, 204);
    }
}
