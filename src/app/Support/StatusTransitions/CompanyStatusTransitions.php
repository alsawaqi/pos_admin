<?php

declare(strict_types=1);

namespace App\Support\StatusTransitions;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyStatusHistory;
use App\Support\Compliance\MerchantActivationRequirements;

/**
 * Authoritative state machine for {@see CompanyStatus} transitions.
 *
 * The product rules:
 * - Onboarding may move to Active (verification complete), Suspended
 *   (stopped during onboarding — review finding 2026-10-01) or Inactive
 *   (abandoned).
 * - Active may be Suspended (compliance/billing issue) or Inactive (offboarded).
 * - Lifting a suspension returns the merchant to the status it had before
 *   the suspension: Onboarding, or Active. A merchant suspended while
 *   onboarding may also go straight to Active — that is its FIRST
 *   activation, so the verified-documents rule applies
 *   ({@see MerchantActivationRequirements}).
 *   A merchant that was live before can never go back to Onboarding.
 * - Suspended or anything else may become Inactive (closed).
 * - Inactive is NOT terminal (owner decision 2026-10-01): a closed
 *   merchant may be REOPENED, by a Super Admin only, with a written
 *   reason, keeping all of its data. It goes back to Active when it was
 *   ever live ({@see MerchantActivationRequirements::wasActiveBefore()})
 *   and to Onboarding otherwise, so a reopen never skips the
 *   first-activation document rule. No other exit from Inactive.
 *   This class only decides the target; the Super Admin + reason rule is
 *   enforced by TransitionCompanyStatusAction (and CompanyPolicy::reopen
 *   at the HTTP edge).
 */
final class CompanyStatusTransitions
{
    /**
     * Status-only map. Suspended and Inactive list every possible exit;
     * which of them applies to a given merchant is decided by
     * {@see allowedFor()}.
     *
     * @var array<string, list<CompanyStatus>>
     */
    private const ALLOWED = [
        'onboarding' => [CompanyStatus::Active, CompanyStatus::Suspended, CompanyStatus::Inactive],
        'active' => [CompanyStatus::Suspended, CompanyStatus::Inactive],
        'suspended' => [CompanyStatus::Active, CompanyStatus::Onboarding, CompanyStatus::Inactive],
        'inactive' => [CompanyStatus::Active, CompanyStatus::Onboarding],
    ];

    public static function canTransition(CompanyStatus $from, CompanyStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to, self::ALLOWED[$from->value] ?? [], strict: true);
    }

    /**
     * @return list<CompanyStatus>
     */
    public static function allowedFrom(CompanyStatus $from): array
    {
        return self::ALLOWED[$from->value] ?? [];
    }

    /**
     * The transitions this particular merchant may take now.
     *
     * @return list<CompanyStatus>
     */
    public static function allowedFor(Company $company): array
    {
        /** @var CompanyStatus|null $status */
        $status = $company->status;
        if ($status === null) {
            return [];
        }

        if ($status === CompanyStatus::Inactive) {
            // A reopen has exactly one target ({@see reopenTarget()}).
            return [self::reopenTarget($company)];
        }

        $allowed = self::allowedFrom($status);

        if ($status === CompanyStatus::Suspended && self::statusBeforeSuspension($company) === CompanyStatus::Active) {
            // A merchant that was live goes back to Active (or Inactive),
            // never back to Onboarding.
            $allowed = array_values(array_filter(
                $allowed,
                static fn (CompanyStatus $to): bool => $to !== CompanyStatus::Onboarding,
            ));
        }

        return $allowed;
    }

    public static function canTransitionCompany(Company $company, CompanyStatus $to): bool
    {
        return $company->status !== $to && in_array($to, self::allowedFor($company), strict: true);
    }

    /**
     * Is a status change of this merchant a REOPEN of a closed merchant
     * (Super Admin only, written reason required)?
     */
    public static function isReopen(Company $company): bool
    {
        return $company->status === CompanyStatus::Inactive;
    }

    /**
     * Where a reopened merchant goes: back to Active when it was ever
     * live, otherwise back to Onboarding (so its first activation still
     * needs the verified documents). Same "was it ever live" test as the
     * fallback of {@see statusBeforeSuspension()}: activated_at is set,
     * or the status history has a row into Active.
     */
    public static function reopenTarget(Company $company): CompanyStatus
    {
        return MerchantActivationRequirements::wasActiveBefore($company)
            ? CompanyStatus::Active
            : CompanyStatus::Onboarding;
    }

    /**
     * The status a suspended merchant had right before its (latest)
     * suspension: the from_status of the newest history row into
     * Suspended. Falls back to "was it ever active" for merchants whose
     * history does not record it.
     */
    public static function statusBeforeSuspension(Company $company): CompanyStatus
    {
        $from = CompanyStatusHistory::query()
            ->where('company_id', $company->id)
            ->where('to_status', CompanyStatus::Suspended->value)
            ->orderByDesc('id')
            ->value('from_status');

        if ($from instanceof CompanyStatus) {
            return $from;
        }
        if (is_string($from) && CompanyStatus::tryFrom($from) !== null) {
            return CompanyStatus::from($from);
        }

        $everActive = $company->activated_at !== null || CompanyStatusHistory::query()
            ->where('company_id', $company->id)
            ->where('to_status', CompanyStatus::Active->value)
            ->exists();

        return $everActive ? CompanyStatus::Active : CompanyStatus::Onboarding;
    }
}
