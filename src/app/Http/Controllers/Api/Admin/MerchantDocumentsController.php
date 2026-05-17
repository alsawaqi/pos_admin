<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\UploadCompanyDocumentAction;
use App\Data\Admin\UploadCompanyDocumentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadMerchantDocumentRequest;
use App\Http\Resources\Admin\CompanyDocumentResource;
use App\Models\Company;
use App\Models\CompanyDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MerchantDocumentsController extends Controller
{
    public function __construct(
        private readonly UploadCompanyDocumentAction $uploadDocument,
    ) {}

    public function index(Request $request, Company $merchant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompanyDocument::class);

        $documents = $merchant->documents()
            ->when($request->filled('document_type'), function ($q) use ($request): void {
                $q->where('document_type', $request->string('document_type')->value());
            })
            ->when($request->filled('verification_status'), function ($q) use ($request): void {
                $q->where('verification_status', $request->string('verification_status')->value());
            })
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 25), 100));

        return CompanyDocumentResource::collection($documents);
    }

    public function store(UploadMerchantDocumentRequest $request, Company $merchant): JsonResponse
    {
        $this->authorize('upload', CompanyDocument::class);

        $payload = $request->validated();
        $payload['file'] = $request->file('file');

        $data = UploadCompanyDocumentData::from($payload);

        $document = $this->uploadDocument->handle($merchant, $data, $request->user());

        return CompanyDocumentResource::make($document)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Company $merchant, CompanyDocument $document): CompanyDocumentResource
    {
        $this->authorize('view', $document);
        $this->ensureBelongs($merchant, $document);

        return CompanyDocumentResource::make($document);
    }

    public function download(Company $merchant, CompanyDocument $document): StreamedResponse
    {
        $this->authorize('view', $document);
        $this->ensureBelongs($merchant, $document);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    public function destroy(Company $merchant, CompanyDocument $document): JsonResponse
    {
        $this->authorize('delete', $document);
        $this->ensureBelongs($merchant, $document);

        Storage::disk($document->disk)->delete($document->path);
        $document->delete();

        return response()->json(status: 204);
    }

    private function ensureBelongs(Company $merchant, CompanyDocument $document): void
    {
        abort_if($document->company_id !== $merchant->id, 404);
    }
}
