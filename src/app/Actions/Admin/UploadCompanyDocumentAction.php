<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\UploadCompanyDocumentData;
use App\Data\Security\AuditLogData;
use App\Enums\DocumentVerificationStatus;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class UploadCompanyDocumentAction
{
    private const DEFAULT_DISK = 'documents';

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Company $company, UploadCompanyDocumentData $data, ?User $actor = null): CompanyDocument
    {
        return DB::transaction(function () use ($company, $data, $actor): CompanyDocument {
            $file = $data->file;
            $sha256 = hash_file('sha256', $file->getRealPath());
            $extension = $file->getClientOriginalExtension();
            $disk = self::DEFAULT_DISK;
            $directory = "companies/{$company->uuid}";
            $filename = Str::random(40).($extension !== '' ? ".{$extension}" : '');

            $path = Storage::disk($disk)->putFileAs($directory, $file, $filename);

            if ($path === false) {
                throw new \RuntimeException('Failed to persist company document.');
            }

            /** @var CompanyDocument $document */
            $document = CompanyDocument::query()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $company->id,
                'document_type' => $data->documentType,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize() ?: 0,
                'sha256' => $sha256,
                'uploaded_by_user_id' => $actor?->id,
                'verification_status' => DocumentVerificationStatus::Pending,
                'issued_at' => $data->issuedAt,
                'expires_at' => $data->expiresAt,
                'notes' => $data->notes,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'company.document.uploaded',
                actorUserId: $actor?->id,
                companyId: $company->id,
                auditableType: CompanyDocument::class,
                auditableId: $document->id,
                newValues: $document->only(['uuid', 'document_type', 'original_name', 'size_bytes', 'sha256', 'expires_at']),
            ));

            return $document;
        });
    }
}
