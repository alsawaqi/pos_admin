<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\SyncCompanyActivitiesAction;
use App\Data\Admin\CompanyActivitySelectionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncMerchantActivitiesRequest;
use App\Http\Resources\Admin\BusinessActivityResource;
use App\Models\Company;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MerchantActivitiesController extends Controller
{
    public function __construct(
        private readonly SyncCompanyActivitiesAction $syncActivities,
    ) {}

    public function update(SyncMerchantActivitiesRequest $request, Company $merchant): AnonymousResourceCollection
    {
        $this->authorize('manageActivities', $merchant);

        $selections = array_map(
            static fn (array $entry): CompanyActivitySelectionData => CompanyActivitySelectionData::from([
                'businessActivityId' => $entry['business_activity_id'],
                'isPrimary' => (bool) ($entry['is_primary'] ?? false),
            ]),
            $request->input('activities', []),
        );

        $merchant = $this->syncActivities->handle($merchant, $selections, $request->user());

        return BusinessActivityResource::collection($merchant->activities);
    }
}
