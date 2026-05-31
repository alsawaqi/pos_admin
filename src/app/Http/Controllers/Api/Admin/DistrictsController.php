<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDistrictRequest;
use App\Http\Requests\Admin\UpdateDistrictRequest;
use App\Http\Resources\Admin\DistrictResource;
use App\Models\Geo\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class DistrictsController extends Controller
{
    private const AUDIT_FIELDS = ['region_id', 'name'];

    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = District::query()->withCount('cities');

        if ($request->filled('region_id')) {
            $query->where('region_id', (int) $request->input('region_id'));
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where('name', 'like', "%{$term}%");
        }

        return DistrictResource::collection($query->orderBy('name')->get());
    }

    public function store(StoreDistrictRequest $request): JsonResponse
    {
        $this->ensureCanManage($request);

        $district = DB::transaction(function () use ($request): District {
            /** @var District $district */
            $district = District::query()->create($request->validated());

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'district.created',
                actorUserId: $request->user()?->id,
                auditableType: District::class,
                auditableId: $district->id,
                newValues: $district->only(self::AUDIT_FIELDS),
            ));

            return $district;
        });

        return DistrictResource::make($district->loadCount('cities'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateDistrictRequest $request, District $district): DistrictResource
    {
        $this->ensureCanManage($request);

        $district = DB::transaction(function () use ($request, $district): District {
            $before = $district->only(self::AUDIT_FIELDS);
            $district->fill($request->validated());

            if ($district->isDirty()) {
                $district->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'district.updated',
                    actorUserId: $request->user()?->id,
                    auditableType: District::class,
                    auditableId: $district->id,
                    oldValues: $before,
                    newValues: $district->only(self::AUDIT_FIELDS),
                ));
            }

            return $district;
        });

        return DistrictResource::make($district->loadCount('cities'));
    }

    public function destroy(Request $request, District $district): JsonResponse
    {
        $this->ensureCanManage($request);

        if ($district->cities()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a district that still has cities. Remove them first.',
            ], 409);
        }

        DB::transaction(function () use ($request, $district): void {
            $before = $district->only(self::AUDIT_FIELDS);
            $district->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'district.deleted',
                actorUserId: $request->user()?->id,
                auditableType: District::class,
                auditableId: $district->id,
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
