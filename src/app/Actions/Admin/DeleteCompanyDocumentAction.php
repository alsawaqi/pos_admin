<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\CompanyDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Remove a merchant compliance document from the admin views
 * (LAUNCH-P1 low finding "deleting a document is not audited and
 * destroys the file").
 *
 * The row is SOFT-deleted and the stored file is deliberately kept:
 * a CR or owner ID that was once on file is compliance evidence, and
 * a mistaken delete must stay recoverable from the database row plus
 * the private documents disk. Every delete writes an audit row with
 * the document's identity so the trail shows who removed what.
 */
final readonly class DeleteCompanyDocumentAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(CompanyDocument $document, ?User $actor = null): void
    {
        DB::transaction(function () use ($document, $actor): void {
            $document->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'company.document.deleted',
                actorUserId: $actor?->id,
                companyId: $document->company_id,
                auditableType: CompanyDocument::class,
                auditableId: $document->id,
                oldValues: [
                    'uuid' => $document->uuid,
                    'document_type' => $document->document_type?->value,
                    'original_name' => $document->original_name,
                    'verification_status' => $document->verification_status?->value,
                    'sha256' => $document->sha256,
                ],
                metadata: [
                    // The file stays on the private disk on purpose.
                    'file_kept' => true,
                    'disk' => $document->disk,
                ],
            ));
        });
    }
}
