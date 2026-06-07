<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\RejectCompanyDocumentAction;
use App\Actions\Admin\VerifyCompanyDocumentAction;
use App\Data\Admin\RejectCompanyDocumentData;
use App\Data\Admin\VerifyCompanyDocumentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectMerchantDocumentRequest;
use App\Http\Requests\Admin\VerifyMerchantDocumentRequest;
use App\Http\Resources\Admin\CompanyDocumentResource;
use App\Models\Company;
use App\Models\CompanyDocument;

class MerchantDocumentVerificationController extends Controller
{
    public function __construct(
        private readonly VerifyCompanyDocumentAction $verifyDocument,
        private readonly RejectCompanyDocumentAction $rejectDocument,
    ) {}

    public function verify(VerifyMerchantDocumentRequest $request, Company $merchant, CompanyDocument $document): CompanyDocumentResource
    {
        $this->authorize('verify', $document);
        $this->ensureBelongs($merchant, $document);

        $data = VerifyCompanyDocumentData::from($request->validated());

        return CompanyDocumentResource::make(
            $this->verifyDocument->handle($document, $data, $request->user())
        );
    }

    public function reject(RejectMerchantDocumentRequest $request, Company $merchant, CompanyDocument $document): CompanyDocumentResource
    {
        $this->authorize('reject', $document);
        $this->ensureBelongs($merchant, $document);

        $data = RejectCompanyDocumentData::from($request->validated());

        return CompanyDocumentResource::make(
            $this->rejectDocument->handle($document, $data, $request->user())
        );
    }

    private function ensureBelongs(Company $merchant, CompanyDocument $document): void
    {
        abort_if($document->company_id !== $merchant->id, 404);
    }
}
