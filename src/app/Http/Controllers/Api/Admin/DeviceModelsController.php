<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateDeviceModelAction;
use App\Actions\Admin\DeleteDeviceModelAction;
use App\Actions\Admin\UpdateDeviceModelAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDeviceModelRequest;
use App\Http\Requests\Admin\UpdateDeviceModelRequest;
use App\Http\Resources\Admin\DeviceModelResource;
use App\Models\DeviceMake;
use App\Models\DeviceModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Endpoints for the Device Models catalogue, nested under makes.
 *
 * Routes (admin.php, scoped binding):
 *   GET    /device-makes/{make}/models                 — list
 *   POST   /device-makes/{make}/models                 — add
 *   PATCH  /device-makes/{make}/models/{model}         — edit
 *   DELETE /device-makes/{make}/models/{model}         — delete
 *
 * Scoped binding means /device-makes/A/models/{B's model id}
 * naturally returns 404 — the model must belong to the URL's make.
 */
class DeviceModelsController extends Controller
{
    public function __construct(
        private readonly CreateDeviceModelAction $createModel,
        private readonly UpdateDeviceModelAction $updateModel,
        private readonly DeleteDeviceModelAction $deleteModel,
    ) {}

    public function index(Request $request, DeviceMake $make): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DeviceModel::class);

        $query = $make->models()->withCount('devices');

        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where('name', 'like', "%{$term}%");
        }

        return DeviceModelResource::collection($query->get());
    }

    public function store(StoreDeviceModelRequest $request, DeviceMake $make): JsonResponse
    {
        $this->authorize('create', DeviceModel::class);

        $model = $this->createModel->handle($make, $request->validated(), $request->user());

        return DeviceModelResource::make($model)
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateDeviceModelRequest $request,
        DeviceMake $make,
        DeviceModel $model,
    ): DeviceModelResource {
        $this->authorize('update', $model);

        // scopeBindings already enforces this, but explicit beats
        // implicit when the audit trail is involved.
        if ($model->make_id !== $make->id) {
            abort(404);
        }

        $updated = $this->updateModel->handle($model, $request->validated(), $request->user());

        return DeviceModelResource::make($updated->loadCount('devices'));
    }

    public function destroy(
        Request $request,
        DeviceMake $make,
        DeviceModel $model,
    ): JsonResponse {
        $this->authorize('delete', $model);

        if ($model->make_id !== $make->id) {
            abort(404);
        }

        try {
            $this->deleteModel->handle($model, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(null, 204);
    }
}
