<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\UpsertMerchantCommissionProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpsertMerchantCommissionProfileRequest;
use App\Http\Resources\Admin\MerchantCommissionProfileResource;
use App\Models\Company;
use App\Models\MerchantCommissionProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-merchant commission profile (the platform's revenue split for this
 * merchant's sales). Nested under the merchant so the Company policy +
 * tenant guard always have the parent in scope.
 *
 * Reads/writes the POS-owned pos_commission_profiles + pos_commission_shares
 * tables — never the charity-owned `commission_profiles`.
 */
class MerchantCommissionProfileController extends Controller
{
    public function __construct(
        private readonly UpsertMerchantCommissionProfileAction $upsert,
    ) {}

    /**
     * GET /admin/api/v1/merchants/{merchant:uuid}/commission-profile
     *
     * Returns the merchant's profile, or a transient default (merchant
     * keeps 100%, no shares) when none has been configured yet so the
     * editor always has a shape to bind to.
     */
    public function show(Company $merchant): MerchantCommissionProfileResource
    {
        $this->authorize('view', $merchant);

        $profile = $merchant->commissionProfile()->with('shares')->first();

        if ($profile === null) {
            $profile = new MerchantCommissionProfile([
                'company_id' => $merchant->id,
                'is_active' => true,
                'merchant_percent' => 100,
            ]);
            $profile->setRelation('shares', new Collection);
        }

        return MerchantCommissionProfileResource::make($profile);
    }

    /**
     * PUT /admin/api/v1/merchants/{merchant:uuid}/commission-profile
     */
    public function update(UpsertMerchantCommissionProfileRequest $request, Company $merchant): JsonResponse
    {
        $this->authorize('update', $merchant);

        /** @var array<int, array{party_type: string, label: string, percent: int|float|string}> $shares */
        $shares = $request->validated('shares', []);
        $isActive = (bool) $request->validated('is_active', true);

        $profile = $this->upsert->handle($merchant, $shares, $isActive, $request->user());

        // Always 200 — this is an idempotent upsert, so a freshly-created
        // profile shouldn't surface as 201 (the resource would otherwise
        // infer that from the model's wasRecentlyCreated flag).
        return MerchantCommissionProfileResource::make($profile)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
