<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\BusinessActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Apply a PATCH to a business-activity row. Only fields actually
 * present in `$attributes` are written — the controller passes the
 * validated() output which strips absent fields automatically.
 *
 * The before/after diff lands in the audit log so support can
 * answer "who flipped this activity off and when".
 */
final readonly class UpdateBusinessActivityAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(BusinessActivity $activity, array $attributes, ?User $actor = null): BusinessActivity
    {
        return DB::transaction(function () use ($activity, $attributes, $actor): BusinessActivity {
            $before = $activity->only([
                'code', 'name_en', 'name_ar', 'category',
                'isic_code', 'description_en', 'description_ar',
                'is_active', 'display_order',
            ]);

            $activity->fill($attributes);

            // No-op when the admin opened the modal and clicked save
            // without changing anything. Skip the DB write + audit
            // entry in that case.
            if (! $activity->isDirty()) {
                return $activity;
            }

            $activity->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'business_activity.updated',
                actorUserId: $actor?->id,
                auditableType: BusinessActivity::class,
                auditableId: $activity->id,
                oldValues: $before,
                newValues: $activity->only(array_keys($before)),
            ));

            return $activity->refresh();
        });
    }
}
