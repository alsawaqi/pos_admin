<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\ContentAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Review an advertiser-submitted content asset: approve it (eligible for
 * sliders) or reject it with a note the advertiser sees on their Approvals
 * page. Stamps reviewed_at + writes an audit-log entry. pos_admin only ever
 * writes the review fields on this marketing-api-owned table.
 */
final readonly class ReviewContentAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function approve(ContentAsset $asset, ?User $actor = null): ContentAsset
    {
        return $this->apply($asset, 'approved', null, $actor);
    }

    public function reject(ContentAsset $asset, ?string $note, ?User $actor = null): ContentAsset
    {
        return $this->apply($asset, 'rejected', $note, $actor);
    }

    private function apply(ContentAsset $asset, string $status, ?string $note, ?User $actor): ContentAsset
    {
        return DB::transaction(function () use ($asset, $status, $note, $actor): ContentAsset {
            $previous = $asset->status;

            $asset->update([
                'status' => $status,
                'review_note' => $note,
                'reviewed_at' => now(),
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'content_asset.'.$status,
                actorUserId: $actor?->id,
                auditableType: ContentAsset::class,
                auditableId: $asset->id,
                oldValues: ['status' => $previous],
                newValues: ['status' => $status, 'review_note' => $note],
            ));

            return $asset;
        });
    }
}
