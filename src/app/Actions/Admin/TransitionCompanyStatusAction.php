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
use App\Support\Compliance\MerchantActivationBlocked;
use App\Support\Compliance\MerchantActivationRequirements;
use App\Support\StatusTransitions\CompanyStatusTransitions;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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

            // Owner decision 2026-10-01: a closed (Inactive) merchant may be
            // reopened by a Super Admin only, with a written reason. All
            // of its data stays; only the status changes (back to Active
            // if it was ever live, otherwise Onboarding — the state
            // machine below allows exactly that one target).
            $reopen = CompanyStatusTransitions::isReopen($company);
            if ($reopen) {
                if ($actor === null || Gate::forUser($actor)->denies('reopen', $company)) {
                    throw new AuthorizationException('Only a Super Admin can reopen a closed merchant.');
                }
                if (trim((string) $data->reason) === '') {
                    throw ValidationException::withMessages([
                        'reason' => 'A written reason is required to reopen a closed merchant.',
                    ]);
                }
            }

            // Per-merchant rules: e.g. lifting a suspension returns to the
            // status before it (a merchant that was live never goes back
            // to onboarding).
            if (! CompanyStatusTransitions::canTransitionCompany($company, $to)) {
                throw new DomainException(
                    "Cannot transition company from {$from->value} to {$to->value}."
                );
            }

            // LAUNCH-P1 P1-19 (owner decision 2026-10-01): the FIRST
            // activation needs a verified CR certificate and a verified
            // owner ID card. Lifting a suspension of a merchant that was
            // live before does not (owner follow-up 2026-10-01).
            if ($to === CompanyStatus::Active && MerchantActivationRequirements::requiredFor($company)) {
                $missing = MerchantActivationRequirements::missing($company);
                if ($missing !== []) {
                    throw new MerchantActivationBlocked($missing);
                }
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

            // Lifting a suspension of a merchant that was still onboarding.
            if ($to === CompanyStatus::Onboarding) {
                $company->suspended_at = null;
                $company->suspension_reason = null;
            }

            $company->save();

            CompanyStatusHistory::query()->create([
                'company_id' => $company->id,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_user_id' => $actor?->id,
                'reason' => $data->reason,
                'metadata' => $reopen ? ['reopened' => true] : null,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                // A reopen has its own event so it stands out in the audit
                // log ("company.status" still finds both).
                event: $reopen ? 'company.status.reopened' : 'company.status.transitioned',
                actorUserId: $actor?->id,
                companyId: $company->id,
                auditableType: Company::class,
                auditableId: $company->id,
                oldValues: ['status' => $from->value],
                newValues: ['status' => $to->value],
                metadata: $reopen
                    ? ['reason' => $data->reason, 'reopened' => true, 'was_live_before' => $to === CompanyStatus::Active]
                    : ['reason' => $data->reason],
            ));

            return $company->refresh();
        });
    }
}
