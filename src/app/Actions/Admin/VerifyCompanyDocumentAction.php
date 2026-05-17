<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\VerifyCompanyDocumentData;
use App\Data\Security\AuditLogData;
use App\Enums\DocumentVerificationStatus;
use App\Models\CompanyDocument;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class VerifyCompanyDocumentAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(CompanyDocument $document, VerifyCompanyDocumentData $data, ?User $actor = null): CompanyDocument
    {
        return DB::transaction(function () use ($document, $data, $actor): CompanyDocument {
            if ($document->verification_status === DocumentVerificationStatus::Verified) {
                throw new DomainException('Document is already verified.');
            }

            $previousStatus = $document->verification_status;

            $document->verification_status = DocumentVerificationStatus::Verified;
            $document->verified_by_user_id = $actor?->id;
            $document->verified_at = now();
            $document->rejection_reason = null;

            if ($data->notes !== null) {
                $document->notes = $data->notes;
            }

            $document->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'company.document.verified',
                actorUserId: $actor?->id,
                companyId: $document->company_id,
                auditableType: CompanyDocument::class,
                auditableId: $document->id,
                oldValues: ['verification_status' => $previousStatus->value],
                newValues: ['verification_status' => DocumentVerificationStatus::Verified->value],
            ));

            return $document->refresh();
        });
    }
}
