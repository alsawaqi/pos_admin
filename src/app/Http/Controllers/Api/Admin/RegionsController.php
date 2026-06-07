<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRegionRequest;
use App\Http\Requests\Admin\UpdateRegionRequest;
use App\Http\Resources\Admin\RegionResource;
use App\Models\Geo\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class RegionsController extends Controller
{
    private const AUDIT_FIELDS = ['country_id', 'name', 'type', 'code', 'is_active'];

    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Region::query()->withCount(['districts', 'cities']);

        if ($request->filled('country_id')) {
            $query->where('country_id', (int) $request->input('country_id'));
        }

        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where('name', 'like', "%{$term}%");
        }

        return RegionResource::collection($query->orderBy('name')->get());
    }

    public function store(StoreRegionRequest $request): JsonResponse
    {
        $this->ensureCanManage($request);

        $region = DB::transaction(function () use ($request): Region {
            /** @var Region $region */
            $region = Region::query()->create($request->validated());

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'region.created',
                actorUserId: $request->user()?->id,
                auditableType: Region::class,
                auditableId: $region->id,
                newValues: $region->only(self::AUDIT_FIELDS),
            ));

            return $region;
        });

        return RegionResource::make($region->loadCount(['districts', 'cities']))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRegionRequest $request, Region $region): RegionResource
    {
        $this->ensureCanManage($request);

        $region = DB::transaction(function () use ($request, $region): Region {
            $before = $region->only(self::AUDIT_FIELDS);
            $region->fill($request->validated());

            if ($region->isDirty()) {
                $region->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'region.updated',
                    actorUserId: $request->user()?->id,
                    auditableType: Region::class,
                    auditableId: $region->id,
                    oldValues: $before,
                    newValues: $region->only(self::AUDIT_FIELDS),
                ));
            }

            return $region;
        });

        return RegionResource::make($region->loadCount(['districts', 'cities']));
    }

    public function destroy(Request $request, Region $region): JsonResponse
    {
        $this->ensureCanManage($request);

        if ($region->districts()->exists() || $region->cities()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a region that still has districts or cities. Remove them first.',
            ], 409);
        }

        DB::transaction(function () use ($request, $region): void {
            $before = $region->only(self::AUDIT_FIELDS);
            $region->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'region.deleted',
                actorUserId: $request->user()?->id,
                auditableType: Region::class,
                auditableId: $region->id,
                oldValues: $before,
            ));
        });

        return response()->json(null, 204);
    }

    private function ensureCanManage(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->can(PlatformPermission::SettingsManage->value),
            403,
        );
    }
}
