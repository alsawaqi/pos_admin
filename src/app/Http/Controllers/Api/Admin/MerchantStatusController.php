<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\TransitionCompanyStatusAction;
use App\Data\Admin\TransitionCompanyStatusData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TransitionMerchantStatusRequest;
use App\Http\Resources\Admin\CompanyDetailResource;
use App\Models\Company;
use App\Support\Compliance\MerchantActivationBlocked;
use App\Support\StatusTransitions\CompanyStatusTransitions;
use DomainException;
use Illuminate\Http\JsonResponse;

class MerchantStatusController extends Controller
{
    public function __construct(
        private readonly TransitionCompanyStatusAction $transition,
    ) {}

    public function store(TransitionMerchantStatusRequest $request, Company $merchant): CompanyDetailResource|JsonResponse
    {
        $this->authorize('transitionStatus', $merchant);

        $data = TransitionCompanyStatusData::from($request->validated());

        try {
            $merchant = $this->transition->handle($merchant, $data, $request->user());
        } catch (MerchantActivationBlocked $e) {
            // LAUNCH-P1 P1-19: list exactly which documents are missing.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'required_documents_missing',
                'missing' => $e->missing,
            ], 422);
        } catch (DomainException $e) {
            // LAUNCH-P1 low finding: an impossible status change is the
            // caller's mistake (422), not a server error (500).
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'invalid_status_transition',
                'allowed' => array_map(
                    static fn ($status): string => $status->value,
                    CompanyStatusTransitions::allowedFor($merchant),
                ),
            ], 422);
        }

        return CompanyDetailResource::make($merchant->load('statusHistory'));
    }
}
