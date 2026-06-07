<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\ScalefusionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live scalefusion (MDM) surface for a single device: the rich detail
 * telemetry + remote control actions the device-detail page renders.
 * Joined to scalefusion purely by pos_devices.kiosk_id (no charity
 * device records involved).
 *
 * Reads are gated by the device 'view' policy (DevicesView); every
 * control action by the 'control' policy (DevicesControl) and written
 * to the audit log. The scalefusion HTTP calls + encoding live in
 * {@see \App\Services\ScalefusionService}.
 */
class DeviceScalefusionController extends Controller
{
    public function __construct(
        private readonly ScalefusionService $scalefusion,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * GET /admin/api/v1/devices/{device:uuid}/scalefusion
     *
     * Full live device detail (RAM, storage, CPU/thermals, OS, IMEI,
     * SIM, Wi-Fi, management, location) from scalefusion's v3 API.
     */
    public function show(Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $kioskId = $this->requireKioskId($device);
        $result = $this->scalefusion->getDevice($kioskId);

        return response()->json(
            ['data' => $result['data']],
            $result['ok'] ? 200 : $this->failureStatus($result),
        );
    }

    /**
     * GET /admin/api/v1/devices/{device:uuid}/scalefusion/locations?date=Y-m-d
     *
     * Normalised GPS route points for a day, oldest-first.
     */
    public function locations(Request $request, Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $kioskId = $this->requireKioskId($device);
        $result = $this->scalefusion->getDeviceLocations($kioskId, $validated['date']);

        return response()->json(
            [
                'data' => is_array($result['data']) ? $result['data'] : [],
                'date' => $validated['date'],
            ],
            $result['ok'] ? 200 : $this->failureStatus($result),
        );
    }

    public function reboot(Request $request, Device $device): JsonResponse
    {
        return $this->control($request, $device, 'reboot', fn (string $id) => $this->scalefusion->reboot($id));
    }

    public function alarm(Request $request, Device $device): JsonResponse
    {
        return $this->control($request, $device, 'alarm', fn (string $id) => $this->scalefusion->sendAlarm($id));
    }

    public function lock(Request $request, Device $device): JsonResponse
    {
        return $this->control($request, $device, 'lock', fn (string $id) => $this->scalefusion->lock([$id]));
    }

    public function unlock(Request $request, Device $device): JsonResponse
    {
        return $this->control($request, $device, 'unlock', fn (string $id) => $this->scalefusion->unlock([$id]));
    }

    public function clearAppData(Request $request, Device $device): JsonResponse
    {
        return $this->control($request, $device, 'clear_app_data', fn (string $id) => $this->scalefusion->clearAppData($id));
    }

    /**
     * Generic scalefusion action: screen_lock, shutdown, reboot,
     * mark_as_lost, mark_as_found, factory_reset, delete_device,
     * buzz_device, rotate_filevault_key.
     */
    public function action(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'action_type' => ['required', 'string', 'in:screen_lock,shutdown,reboot,mark_as_lost,mark_as_found,factory_reset,delete_device,buzz_device,rotate_filevault_key'],
            'lost_mode_message' => ['nullable', 'string', 'max:500'],
            'lost_mode_footnote' => ['nullable', 'string', 'max:500'],
            'lost_mode_phone' => ['nullable', 'string', 'max:100'],
            'wipe_sd_card' => ['nullable', 'boolean'],
        ]);

        return $this->control(
            $request,
            $device,
            'action:'.$validated['action_type'],
            fn (string $id) => $this->scalefusion->runAction($id, $validated['action_type'], [
                'lost_mode_message' => $validated['lost_mode_message'] ?? null,
                'lost_mode_footnote' => $validated['lost_mode_footnote'] ?? null,
                'lost_mode_phone' => $validated['lost_mode_phone'] ?? null,
                'wipe_sd_card' => $request->boolean('wipe_sd_card'),
            ]),
            ['action_type' => $validated['action_type']],
        );
    }

    public function broadcastMessage(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'sender_name' => ['required', 'string', 'max:100'],
            'message_body' => ['required', 'string', 'max:1000'],
            'keep_ringing' => ['nullable', 'boolean'],
            'show_as_dialog' => ['nullable', 'boolean'],
        ]);

        return $this->control(
            $request,
            $device,
            'broadcast_message',
            fn (string $id) => $this->scalefusion->broadcastMessage(
                $id,
                $validated['sender_name'],
                $validated['message_body'],
                $request->boolean('keep_ringing', true),
                $request->boolean('show_as_dialog', true),
            ),
        );
    }

    /**
     * Shared control pipeline: authorise, resolve the kiosk id, run the
     * scalefusion call, audit, relay the outcome.
     *
     * @param  callable(string): array{ok: bool, status: int, data: mixed}  $run
     * @param  array<string, mixed>  $extraMeta
     */
    private function control(Request $request, Device $device, string $action, callable $run, array $extraMeta = []): JsonResponse
    {
        $this->authorize('control', $device);

        $kioskId = $this->requireKioskId($device);
        $result = $run($kioskId);

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'device.scalefusion.'.$action,
            actorUserId: $request->user()?->id,
            companyId: $device->company_id,
            branchId: $device->branch_id,
            auditableType: Device::class,
            auditableId: $device->id,
            metadata: array_merge([
                'kiosk_id' => $kioskId,
                'ok' => $result['ok'],
                'status' => $result['status'],
            ], $extraMeta),
        ));

        return response()->json(
            [
                'ok' => $result['ok'],
                'data' => $result['data'],
            ],
            $result['ok'] ? 200 : $this->failureStatus($result),
        );
    }

    /**
     * A device must carry a kiosk_id to be addressable in scalefusion.
     */
    private function requireKioskId(Device $device): string
    {
        $kioskId = trim((string) $device->kiosk_id);

        abort_if($kioskId === '', 422, 'This device has no scalefusion kiosk id, so it cannot be reached.');

        return $kioskId;
    }

    /**
     * Relay scalefusion's own 4xx (e.g. 404 unknown device); fall back
     * to 502 for transport-level failures (status 0).
     *
     * @param  array{ok: bool, status: int, data: mixed}  $result
     */
    private function failureStatus(array $result): int
    {
        return $result['status'] >= 400 ? $result['status'] : 502;
    }
}
