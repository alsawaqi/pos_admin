<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\CompanyActivitySelectionData;
use App\Data\Security\AuditLogData;
use App\Models\BusinessActivity;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class SyncCompanyActivitiesAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<int, CompanyActivitySelectionData>  $activities
     */
    public function handle(Company $company, array $activities, ?User $actor = null, bool $recordAudit = true): Company
    {
        return DB::transaction(function () use ($company, $activities, $actor, $recordAudit): Company {
            $this->validatePrimaryCount($activities);

            $ids = array_map(static fn (CompanyActivitySelectionData $entry): int => $entry->businessActivityId, $activities);
            $this->validateAllExist($ids);

            $sync = [];
            foreach ($activities as $entry) {
                $sync[$entry->businessActivityId] = ['is_primary' => $entry->isPrimary];
            }

            $previous = $company->activities()->pluck('pos_admin_business_activities.id')->all();
            $company->activities()->sync($sync);

            if ($recordAudit) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'company.activities.synced',
                    actorUserId: $actor?->id,
                    companyId: $company->id,
                    auditableType: Company::class,
                    auditableId: $company->id,
                    oldValues: ['activity_ids' => $previous],
                    newValues: ['activity_ids' => $ids],
                ));
            }

            return $company->load('activities');
        });
    }

    /**
     * @param  array<int, CompanyActivitySelectionData>  $activities
     */
    private function validatePrimaryCount(array $activities): void
    {
        $primaryCount = 0;

        foreach ($activities as $activity) {
            if ($activity->isPrimary) {
                $primaryCount++;
            }
        }

        if ($primaryCount > 1) {
            throw new InvalidArgumentException('A company may have at most one primary business activity.');
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function validateAllExist(array $ids): void
    {
        $existing = BusinessActivity::query()->whereIn('id', $ids)->pluck('id')->all();

        $missing = array_diff($ids, $existing);

        if ($missing !== []) {
            throw new InvalidArgumentException('Unknown business activity IDs: '.implode(', ', $missing));
        }
    }
}
