<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateCompanyAction;
use App\Actions\Admin\DeleteMerchantAction;
use App\Actions\Admin\UpdateCompanyAction;
use App\Data\Admin\CreateCompanyData;
use App\Data\Admin\UpdateCompanyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMerchantRequest;
use App\Http\Requests\Admin\UpdateMerchantRequest;
use App\Http\Resources\Admin\CompanyDetailResource;
use App\Http\Resources\Admin\CompanyResource;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class MerchantsController extends Controller
{
    public function __construct(
        private readonly CreateCompanyAction $createCompany,
        private readonly UpdateCompanyAction $updateCompany,
        private readonly DeleteMerchantAction $deleteMerchant,
    ) {}

    /**
     * DELETE /admin/api/v1/merchants/{merchant:uuid}
     *
     * Soft-deletes the merchant. Refuses with 409 when the
     * merchant still has active branches or devices — the admin
     * must clean those up first so the destructive blast radius
     * stays visible at every step.
     */
    public function destroy(Request $request, Company $merchant): JsonResponse
    {
        $this->authorize('delete', $merchant);

        try {
            $this->deleteMerchant->handle($merchant, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(null, 204);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Company::class);

        $query = Company::query()
            ->withCount(['branches', 'devices', 'documents']);

        if ($request->filled('status')) {
            $statuses = (array) $request->input('status');
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('name_ar', 'like', "%{$term}%")
                    ->orWhere('cr_number', 'like', "%{$term}%")
                    ->orWhere('vat_number', 'like', "%{$term}%")
                    ->orWhere('contact_email', 'like', "%{$term}%");
            });
        }

        if ($request->filled('onboarded_by')) {
            $query->where('onboarded_by_user_id', $request->integer('onboarded_by'));
        }

        $companies = $query
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 25), 100));

        return CompanyResource::collection($companies);
    }

    public function store(StoreMerchantRequest $request): JsonResponse
    {
        $this->authorize('create', Company::class);

        $data = CreateCompanyData::from($request->validated());

        $company = $this->createCompany->handle($data, $request->user());

        // Owners is included alongside activities + statusHistory so
        // the response back to the wizard includes the freshly-saved
        // owner cards without a second round-trip.
        // loadCount keeps branches_count + devices_count fresh on
        // the response so the SPA's Portal Users tab gate (which
        // requires ≥1 of each before inviting) flips correctly
        // after a branch/device is added.
        return CompanyDetailResource::make(
            $company->load(['activities', 'statusHistory', 'owners'])
                ->loadCount(['branches', 'devices']),
        )
            ->response()
            ->setStatusCode(201);
    }

    public function show(Company $merchant): CompanyDetailResource
    {
        $this->authorize('view', $merchant);

        // loadCount alongside load — see the comment in store() for
        // why the counts have to be present in this payload.
        $merchant->load(['activities', 'documents', 'statusHistory', 'owners'])
            ->loadCount(['branches', 'devices']);

        return CompanyDetailResource::make($merchant);
    }

    public function update(UpdateMerchantRequest $request, Company $merchant): CompanyDetailResource
    {
        $this->authorize('update', $merchant);

        $data = UpdateCompanyData::from($request->validated());

        $updated = $this->updateCompany->handle($merchant, $data, $request->user());

        return CompanyDetailResource::make(
            $updated->load(['activities', 'statusHistory', 'owners'])
                ->loadCount(['branches', 'devices']),
        );
    }
}
