<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\AssignDeviceAction;
use App\Actions\Admin\CreateDeviceActivationTokenAction;
use App\Actions\Admin\DecommissionDeviceAction;
use App\Actions\Admin\RegisterDeviceAction;
use App\Actions\Admin\UnassignDeviceAction;
use App\Actions\Admin\UpdateDeviceAction;
use App\Enums\DeviceStatus;
use App\Data\Admin\AssignDeviceData;
use App\Data\Admin\RegisterDeviceData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignDeviceRequest;
use App\Http\Requests\Admin\RegisterDeviceRequest;
use App\Http\Requests\Admin\UnassignDeviceRequest;
use App\Http\Requests\Admin\UpdateDeviceRequest;
use App\Data\Admin\UpdateDeviceData;
use App\Http\Resources\Admin\DeviceResource;
use App\Services\ScalefusionService;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * HTTP entry point for the Admin Portal's Devices section
 * (blueprint §4.4). Thin layer: every endpoint delegates business
 * logic to an Action, every endpoint authorises through
 * {@see \App\Policies\DevicePolicy}.
 *
 * Endpoints (all under /admin/api/v1/devices, routes registered in
 * routes/admin.php):
 *
 *   GET    /devices                — list with filters
 *   POST   /devices                — register a new device
 *   GET    /devices/{device:uuid}  — detail with assignment history
 *   POST   /devices/{device:uuid}/assign   — bind to (company, branch)
 *   POST   /devices/{device:uuid}/unassign — release from current branch
 */
class DevicesController extends Controller
{
    /**
     * Actions are injected so this controller stays trivially mockable
     * and the Actions themselves can be swapped in tests via the
     * service container.
     */
    public function __construct(
        private readonly RegisterDeviceAction $registerDevice,
        private readonly UpdateDeviceAction $updateDevice,
        private readonly AssignDeviceAction $assignDevice,
        private readonly UnassignDeviceAction $unassignDevice,
        private readonly DecommissionDeviceAction $decommissionDevice,
        private readonly CreateDeviceActivationTokenAction $createActivationToken,
    ) {}

    /**
     * POST /admin/api/v1/devices/{device:uuid}/decommission
     *
     * Permanently removes the device from the active fleet:
     * closes any open assignment history row, flips status to
     * Blocked, soft-deletes the row. Optional `reason` in the
     * payload is stamped on the closed history row + the audit
     * event for forensic context.
     *
     * Returns 204 No Content — the device is no longer addressable
     * via the standard endpoint after this so there's nothing
     * meaningful to return.
     */
    public function decommission(Request $request, Device $device): JsonResponse
    {
        $this->authorize('decommission', $device);

        $reason = $request->filled('reason')
            ? trim((string) $request->input('reason'))
            : null;

        $this->decommissionDevice->handle($device, $request->user(), $reason);

        return response()->json(null, 204);
    }

    /**
     * GET /admin/api/v1/devices
     *
     * Returns a paginated list of devices. Front-end filters:
     *   - device_type (single value)
     *   - status (single value or array)
     *   - company_id
     *   - branch_id
     *   - unassigned=true → only devices NOT yet bound to a branch
     *   - search (matches serial, kiosk id, name, label)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Device::class);

        // Eager-load the tenant + branch + catalogue + commission
        // profile summaries the table renders (avoids N+1 across
        // the page). The partial selects keep the payload tight —
        // only the columns the list table actually shows.
        $query = Device::query()
            ->with([
                'company:id,uuid,name,name_ar',
                'branch:id,uuid,name,name_ar,latitude,longitude,geofence_radius_m,company_id',
                'make:id,name',
                'model:id,name,make_id',
                'commissionProfile:id,name,is_active',
                // Bank summary for the fleet-list column. Same
                // partial-select pattern keeps the payload tight.
                'bank:id,name,short_name,is_active',
                // Beneficiary organization summary for the fleet-list column.
                'organization:id,name,is_active',
            ]);

        if ($request->filled('device_type')) {
            $query->where('device_type', $request->string('device_type'));
        }

        if ($request->filled('status')) {
            // status= accepts either a string or an array — the UI
            // sends multiple status filters as repeated query params.
            $statuses = (array) $request->input('status');
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        // "Show me everything I haven't placed yet" — useful when the
        // admin opens the Assign page and wants the candidate list.
        if ($request->boolean('unassigned')) {
            $query->whereNull('branch_id');
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($q) use ($term): void {
                $q->where('serial_number', 'like', "%{$term}%")
                    ->orWhere('kiosk_id', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('label', 'like', "%{$term}%");
            });
        }

        // Newest first feels right because the admin's mental model
        // is "what did I just register?" — paginated, capped at 100
        // per page to keep the response small.
        $devices = $query
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 25), 100));

        if ($request->boolean('with_scalefusion')) {
            $this->attachScalefusion($devices->getCollection());
        }

        return DeviceResource::collection($devices);
    }

    /**
     * POST /admin/api/v1/devices
     *
     * Register a new device by kiosk id. Optional immediate
     * assignment is supported via company_id + branch_id in the
     * payload (RegisterDeviceData accepts them) but the standard
     * admin UI keeps Register and Assign as two separate steps.
     */
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $this->authorize('register', Device::class);

        $data = RegisterDeviceData::from($request->validated());
        $device = $this->registerDevice->handle($data, $request->user());

        return DeviceResource::make($device->load(['company', 'branch', 'make', 'model', 'commissionProfile', 'bank', 'organization']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /admin/api/v1/devices/{device:uuid}
     *
     * Edit a registered device's identity + catalogue + commission /
     * organization bindings. Partial update — only the sent fields change.
     * Assignment (company/branch), terminal_id/bank_id and status are NOT
     * editable here; they have their own assign / unassign / decommission flows.
     */
    public function update(UpdateDeviceRequest $request, Device $device): DeviceResource
    {
        $this->authorize('update', $device);

        $data = UpdateDeviceData::from($request->validated());
        $device = $this->updateDevice->handle($device, $data, $request->user());

        return DeviceResource::make($device->load(['company', 'branch', 'make', 'model', 'commissionProfile', 'bank', 'organization']));
    }

    /**
     * GET /admin/api/v1/devices/{device:uuid}
     *
     * Returns the full device record with company + branch summaries
     * AND the assignment history ledger (newest first).
     */
    public function show(Device $device): DeviceResource
    {
        $this->authorize('view', $device);

        return DeviceResource::make(
            $device->load([
                'company',
                'branch',
                'make',
                'model',
                'commissionProfile',
                // Bank summary for the Show page's overview card.
                'bank',
                // Beneficiary organization for the Show page's overview card.
                'organization',
                // Preload company + branch on each history entry so
                // the detail page can render "moved from X to Y"
                // without further round-trips.
                'assignmentHistory.company:id,name',
                'assignmentHistory.branch:id,name',
            ]),
        );
    }

    /**
     * POST /admin/api/v1/devices/{device:uuid}/assign
     *
     * Bind a registered (or already-assigned) device to a (company,
     * branch). Reassignment closes the prior history row and opens a
     * new one — see {@see AssignDeviceAction}.
     */
    public function assign(AssignDeviceRequest $request, Device $device): DeviceResource
    {
        $this->authorize('assign', $device);

        $data = AssignDeviceData::from($request->validated());
        $device = $this->assignDevice->handle($device, $data, $request->user());

        return DeviceResource::make($device->load(['company', 'branch', 'make', 'model', 'commissionProfile', 'bank', 'organization']));
    }

    /**
     * POST /admin/api/v1/devices/{device:uuid}/activation-token
     *
     * Lane A — Android bridge. Mints a one-shot activation code
     * for the device. The plaintext code is returned ONCE in the
     * response (admin shows it to the floor tech once, then it
     * disappears — the DB only ever stores sha256). The Android
     * cashier app POSTs the code to pos_merchant's
     * /api/devices/activate endpoint to exchange it for a
     * long-lived Sanctum personal-access token.
     *
     * Refuses on unassigned / blocked / inactive devices: an
     * activation code needs an assignable device with a branch + company.
     * Returns 409 in that case (state conflict, not validation).
     *
     * Idempotent-friendly: minting a new code does NOT invalidate
     * prior unconsumed codes for the same device. Use the
     * separate revoke endpoint (TODO Lane A2.1) if you need to
     * kill a leaked code. For now the 30-minute TTL is the
     * primary defence — keep the TTL short.
     */
    public function issueActivationToken(Request $request, Device $device): JsonResponse
    {
        $this->authorize('issueActivationToken', $device);

        if ($device->branch_id === null || $device->company_id === null) {
            return response()->json([
                'message' => 'Device must be assigned to a branch before an activation code can be issued.',
            ], 409);
        }
        if (in_array($device->status, [DeviceStatus::Blocked, DeviceStatus::Inactive], true)) {
            return response()->json([
                'message' => 'Blocked or inactive devices cannot receive new activation codes.',
            ], 409);
        }

        $plainCode = $this->createActivationToken->handle($device, $request->user());

        return response()->json([
            'activation_code' => $plainCode,
            // Surfaced so the UI can render a count-down timer
            // — the merchant knows exactly how long the code is
            // valid for.
            'expires_in_minutes' => 30,
        ], 201);
    }

    /**
     * POST /admin/api/v1/devices/{device:uuid}/unassign
     *
     * Release a device from its current branch. The history row
     * stays in the ledger (with `unassigned_at` stamped); the device
     * itself drops back to status=registered, ready for re-use.
     */
    public function unassign(UnassignDeviceRequest $request, Device $device): DeviceResource
    {
        $this->authorize('unassign', $device);

        $reason = $request->input('reason');
        $device = $this->unassignDevice->handle(
            $device,
            is_string($reason) ? $reason : null,
            $request->user(),
        );

        return DeviceResource::make($device->load(['company', 'branch', 'make', 'model', 'commissionProfile', 'bank', 'organization']));
    }

    /**
     * Merge live scalefusion (MDM) status onto the current page of
     * devices, joined by kiosk_id only. Transport failures degrade to a
     * null scalefusion entry per row (the service swallows errors).
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Device>  $devices
     */
    private function attachScalefusion(\Illuminate\Support\Collection $devices): void
    {
        $ids = $devices->pluck('kiosk_id')->filter()->unique()->values()->all();
        $map = $ids === [] ? [] : app(ScalefusionService::class)->findDevicesByIds($ids);

        $devices->each(function (Device $device) use ($map): void {
            $device->setAttribute('scalefusion', $map[(string) $device->kiosk_id] ?? null);
        });
    }
}
