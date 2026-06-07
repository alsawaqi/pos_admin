<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\BusinessActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Insert a new business-activity reference row + write an audit log
 * entry. Wrapped in a transaction so partial failures cannot leave
 * an activity without its corresponding audit trail.
 */
final readonly class CreateBusinessActivityAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, ?User $actor = null): BusinessActivity
    {
        return DB::transaction(function () use ($attributes, $actor): BusinessActivity {
            /** @var BusinessActivity $activity */
            $activity = BusinessActivity::query()->create([
                'code' => $attributes['code'],
                'name_en' => $attributes['name_en'],
                'name_ar' => $attributes['name_ar'],
                'category' => $attributes['category'],
                'isic_code' => $attributes['isic_code'] ?? null,
                'description_en' => $attributes['description_en'] ?? null,
                'description_ar' => $attributes['description_ar'] ?? null,
                'is_active' => $attributes['is_active'] ?? true,
                'display_order' => $attributes['display_order'] ?? 0,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'business_activity.created',
                actorUserId: $actor?->id,
                // No company_id — this is platform-wide reference
                // data, not merchant-scoped.
                auditableType: BusinessActivity::class,
                auditableId: $activity->id,
                newValues: $activity->only([
                    'code', 'name_en', 'name_ar', 'category',
                    'is_active', 'display_order',
                ]),
            ));

            return $activity;
        });
    }
}
