<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\UpdateSettingsAction;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Http\Resources\Admin\SettingResource;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Admin endpoints for the platform Settings page.
 *
 *   GET   /admin/api/v1/settings   → all rows, grouped by group_key
 *   PATCH /admin/api/v1/settings   → bulk update key→value pairs
 *
 * Read is open to any authenticated admin (the Settings UI itself
 * is sidebar-gated by SettingsManage, but the API allows read so a
 * future surface that wants to show the platform name doesn't
 * require an extra permission). Writes require SettingsManage.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly UpdateSettingsAction $updateSettings,
    ) {}

    /**
     * GET /admin/api/v1/settings
     *
     * Returns the full catalogue. The frontend re-groups by
     * `group_key` to render tabs. Sorted by (group_key,
     * display_order) so the response can be rendered directly
     * without a client-side sort.
     */
    public function index(): JsonResponse
    {
        $rows = Setting::query()
            ->orderBy('group_key')
            ->orderBy('display_order')
            ->orderBy('key')
            ->get();

        return response()->json([
            'data' => SettingResource::collection($rows)->resolve(),
        ]);
    }

    /**
     * PATCH /admin/api/v1/settings
     *
     * Body: `{ "settings": { "general.support_email": "x@x", ... } }`.
     * Returns the updated catalogue so the frontend doesn't need a
     * follow-up GET.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can(PlatformPermission::SettingsManage->value)) {
            abort(403);
        }

        try {
            $this->updateSettings->handle(
                (array) $request->validated('settings'),
                $user,
            );
        } catch (RuntimeException $e) {
            // Unknown key from the action — surface as 422 so the
            // SPA can show the message inline rather than 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->index();
    }
}
