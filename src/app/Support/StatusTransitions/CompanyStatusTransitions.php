<?php

declare(strict_types=1);

namespace App\Support\StatusTransitions;

use App\Enums\CompanyStatus;

/**
 * Authoritative state machine for {@see CompanyStatus} transitions.
 *
 * The product rules:
 * - Onboarding may move to Active (verification complete) or Inactive (abandoned).
 * - Active may be Suspended (compliance/billing issue) or Inactive (offboarded).
 * - Suspended may return to Active (issue resolved) or Inactive (terminated).
 * - Inactive is terminal — re-activation requires a fresh onboarding record.
 */
final class CompanyStatusTransitions
{
    /**
     * @var array<string, list<CompanyStatus>>
     */
    private const ALLOWED = [
        'onboarding' => [CompanyStatus::Active, CompanyStatus::Inactive],
        'active' => [CompanyStatus::Suspended, CompanyStatus::Inactive],
        'suspended' => [CompanyStatus::Active, CompanyStatus::Inactive],
        'inactive' => [],
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
}
