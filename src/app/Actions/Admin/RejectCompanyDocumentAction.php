<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\RejectCompanyDocumentData;
use App\Data\Security\AuditLogData;
use App\Enums\DocumentVerificationStatus;
use App\Models\CompanyDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RejectCompanyDocumentAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(CompanyDocument $document, RejectCompanyDocumentData $data, ?User $actor = null): CompanyDocument
    {
        return DB::transaction(function () use ($document, $data, $actor): CompanyDocument {
            $previousStatus = $document->verification_status;

            $document->verification_status = DocumentVerificationStatus::Rejected;
            $document->verified_by_user_id = $actor?->id;
            $document->verified_at = now();
            $document->rejection_reason = $data->reason;
            $document->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'company.document.rejected',
                actorUserId: $actor?->id,
                companyId: $document->company_id,
                auditableType: CompanyDocument::class,
                auditableId: $document->id,
                oldValues: ['verification_status' => $previousStatus->value],
                newValues: ['verification_status' => DocumentVerificationStatus::Rejected->value],
                metadata: ['reason' => $data->reason],
            ));

            return $document->refresh();
        });
    }
}
