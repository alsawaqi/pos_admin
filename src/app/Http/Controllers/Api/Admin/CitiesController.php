<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCityRequest;
use App\Http\Requests\Admin\UpdateCityRequest;
use App\Http\Resources\Admin\CityResource;
use App\Models\Geo\City;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CitiesController extends Controller
{
    private const AUDIT_FIELDS = ['region_id', 'district_id', 'name', 'postal_code', 'is_active'];

    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = City::query();

        if ($request->filled('district_id')) {
            $query->where('district_id', (int) $request->input('district_id'));
        }

        if ($request->filled('region_id')) {
            $query->where('region_id', (int) $request->input('region_id'));
        }

        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where('name', 'like', "%{$term}%");
        }

        return CityResource::collection($query->orderBy('name')->get());
    }

    public function store(StoreCityRequest $request): JsonResponse
    {
        $this->ensureCanManage($request);

        $city = DB::transaction(function () use ($request): City {
            /** @var City $city */
            $city = City::query()->create($request->validated());

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'city.created',
                actorUserId: $request->user()?->id,
                auditableType: City::class,
                auditableId: $city->id,
                newValues: $city->only(self::AUDIT_FIELDS),
            ));

            return $city;
        });

        return CityResource::make($city)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateCityRequest $request, City $city): CityResource
    {
        $this->ensureCanManage($request);

        $city = DB::transaction(function () use ($request, $city): City {
            $before = $city->only(self::AUDIT_FIELDS);
            $city->fill($request->validated());

            if ($city->isDirty()) {
                $city->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'city.updated',
                    actorUserId: $request->user()?->id,
                    auditableType: City::class,
                    auditableId: $city->id,
                    oldValues: $before,
                    newValues: $city->only(self::AUDIT_FIELDS),
                ));
            }

            return $city;
        });

        return CityResource::make($city);
    }

    public function destroy(Request $request, City $city): JsonResponse
    {
        $this->ensureCanManage($request);

        DB::transaction(function () use ($request, $city): void {
            $before = $city->only(self::AUDIT_FIELDS);
            $city->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'city.deleted',
                actorUserId: $request->user()?->id,
                auditableType: City::class,
                auditableId: $city->id,
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
