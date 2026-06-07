<?php

declare(strict_types=1);

namespace App\Jobs\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\DocumentVerificationStatus;
use App\Models\CompanyDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Daily sweep over company documents:
 * - Flips documents whose expires_at has passed to {@see DocumentVerificationStatus::Expired}
 *   so the UI can surface them.
 * - Emits an audit log entry per state change so the platform team has a
 *   trail. Documents expiring within {@see self::WARNING_WINDOW_DAYS} are
 *   surfaced to the dashboard query layer but not mutated here.
 */
class ScanExpiringCompanyDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const WARNING_WINDOW_DAYS = 30;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(WriteAuditLogAction $writeAuditLog): void
    {
        $now = Carbon::now();

        CompanyDocument::query()
            ->where('verification_status', '!=', DocumentVerificationStatus::Expired->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now->startOfDay())
            ->chunkById(200, function ($documents) use ($writeAuditLog): void {
                foreach ($documents as $document) {
                    /** @var CompanyDocument $document */
                    $previous = $document->verification_status;

                    $document->verification_status = DocumentVerificationStatus::Expired;
                    $document->save();

                    $writeAuditLog->handle(new AuditLogData(
                        event: 'company.document.expired',
                        companyId: $document->company_id,
                        auditableType: CompanyDocument::class,
                        auditableId: $document->id,
                        oldValues: ['verification_status' => $previous?->value],
                        newValues: ['verification_status' => DocumentVerificationStatus::Expired->value],
                        metadata: ['expires_at' => $document->expires_at?->toDateString()],
                    ));
                }
            });
    }
}
