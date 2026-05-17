<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateCompanyAction;
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

class MerchantsController extends Controller
{
    public function __construct(
        private readonly CreateCompanyAction $createCompany,
        private readonly UpdateCompanyAction $updateCompany,
    ) {}

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

        return CompanyDetailResource::make($company->load(['activities', 'statusHistory']))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Company $merchant): CompanyDetailResource
    {
        $this->authorize('view', $merchant);

        $merchant->load(['activities', 'documents', 'statusHistory']);

        return CompanyDetailResource::make($merchant);
    }

    public function update(UpdateMerchantRequest $request, Company $merchant): CompanyDetailResource
    {
        $this->authorize('update', $merchant);

        $data = UpdateCompanyData::from($request->validated());

        $updated = $this->updateCompany->handle($merchant, $data, $request->user());

        return CompanyDetailResource::make($updated->load(['activities', 'statusHistory']));
    }
}
