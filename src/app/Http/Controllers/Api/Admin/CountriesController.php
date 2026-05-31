<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCountryRequest;
use App\Http\Requests\Admin\UpdateCountryRequest;
use App\Http\Resources\Admin\CountryResource;
use App\Models\Geo\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CountriesController extends Controller
{
    private const AUDIT_FIELDS = ['name', 'iso_code', 'phone_code', 'is_active'];

    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Country::query()->withCount('regions');

        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('iso_code', 'like', "%{$term}%");
            });
        }

        return CountryResource::collection($query->orderBy('name')->get());
    }

    public function store(StoreCountryRequest $request): JsonResponse
    {
        $this->ensureCanManage($request);

        $country = DB::transaction(function () use ($request): Country {
            /** @var Country $country */
            $country = Country::query()->create($request->validated());

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'country.created',
                actorUserId: $request->user()?->id,
                auditableType: Country::class,
                auditableId: $country->id,
                newValues: $country->only(self::AUDIT_FIELDS),
            ));

            return $country;
        });

        return CountryResource::make($country->loadCount('regions'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateCountryRequest $request, Country $country): CountryResource
    {
        $this->ensureCanManage($request);

        $country = DB::transaction(function () use ($request, $country): Country {
            $before = $country->only(self::AUDIT_FIELDS);
            $country->fill($request->validated());

            if ($country->isDirty()) {
                $country->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'country.updated',
                    actorUserId: $request->user()?->id,
                    auditableType: Country::class,
                    auditableId: $country->id,
                    oldValues: $before,
                    newValues: $country->only(self::AUDIT_FIELDS),
                ));
            }

            return $country;
        });

        return CountryResource::make($country->loadCount('regions'));
    }

    public function destroy(Request $request, Country $country): JsonResponse
    {
        $this->ensureCanManage($request);

        if ($country->regions()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a country that still has regions. Remove or reassign them first.',
            ], 409);
        }

        DB::transaction(function () use ($request, $country): void {
            $before = $country->only(self::AUDIT_FIELDS);
            $country->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'country.deleted',
                actorUserId: $request->user()?->id,
                auditableType: Country::class,
                auditableId: $country->id,
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
