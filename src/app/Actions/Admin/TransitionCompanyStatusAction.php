<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\TransitionCompanyStatusData;
use App\Data\Security\AuditLogData;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyStatusHistory;
use App\Models\User;
use App\Support\StatusTransitions\CompanyStatusTransitions;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class TransitionCompanyStatusAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Company $company, TransitionCompanyStatusData $data, ?User $actor = null): Company
    {
        return DB::transaction(function () use ($company, $data, $actor): Company {
            /** @var CompanyStatus $from */
            $from = $company->status;
            $to = $data->targetStatus;

            if (! CompanyStatusTransitions::canTransition($from, $to)) {
                throw new DomainException(
                    "Cannot transition company from {$from->value} to {$to->value}."
                );
            }

            $company->status = $to;

            if ($to === CompanyStatus::Active) {
                $company->activated_at ??= now();
                $company->suspended_at = null;
                $company->suspension_reason = null;
            }

            if ($to === CompanyStatus::Suspended) {
                $company->suspended_at = now();
                $company->suspension_reason = $data->reason;
            }

            $company->save();

            CompanyStatusHistory::query()->create([
                'company_id' => $company->id,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_user_id' => $actor?->id,
                'reason' => $data->reason,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'company.status.transitioned',
                actorUserId: $actor?->id,
                companyId: $company->id,
                auditableType: Company::class,
                auditableId: $company->id,
                oldValues: ['status' => $from->value],
                newValues: ['status' => $to->value],
                metadata: ['reason' => $data->reason],
            ));

            return $company->refresh();
        });
    }
}
