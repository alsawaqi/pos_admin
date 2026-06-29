<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\MarketingSlider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Soft-delete a slider (it stops being served + drops out of the list). Items +
 * targets are left in place — they are unreachable through the trashed slider.
 */
final readonly class DeleteSliderAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(MarketingSlider $slider, ?User $actor = null): void
    {
        DB::transaction(function () use ($slider, $actor): void {
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'marketing_slider.deleted',
                actorUserId: $actor?->id,
                auditableType: MarketingSlider::class,
                auditableId: $slider->id,
                oldValues: $slider->only(['name', 'status']),
            ));

            $slider->delete();
        });
    }
}
