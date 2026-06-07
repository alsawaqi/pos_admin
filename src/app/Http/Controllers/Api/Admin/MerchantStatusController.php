<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\TransitionCompanyStatusAction;
use App\Data\Admin\TransitionCompanyStatusData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TransitionMerchantStatusRequest;
use App\Http\Resources\Admin\CompanyDetailResource;
use App\Models\Company;

class MerchantStatusController extends Controller
{
    public function __construct(
        private readonly TransitionCompanyStatusAction $transition,
    ) {}

    public function store(TransitionMerchantStatusRequest $request, Company $merchant): CompanyDetailResource
    {
        $this->authorize('transitionStatus', $merchant);

        $data = TransitionCompanyStatusData::from($request->validated());

        $merchant = $this->transition->handle($merchant, $data, $request->user());

        return CompanyDetailResource::make($merchant->load('statusHistory'));
    }
}
