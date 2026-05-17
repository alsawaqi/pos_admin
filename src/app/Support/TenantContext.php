<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use Closure;

/**
 * Request-scoped tenant context.
 *
 * Holds the currently active company for the in-flight request.
 * Models that opt into BelongsToCompany consult this to scope queries.
 *
 * Platform admins generally have no tenant set; controllers that act on a
 * specific company should wrap operations in {@see self::run()} so the global
 * scope is automatically applied while the closure executes.
 *
 * Spatie permission team mapping: Spatie's pivot tables (model_has_roles,
 * model_has_permissions) have team_id in the composite primary key, so the
 * value can never be NULL in Postgres. Platform-wide roles therefore use the
 * sentinel {@see self::PLATFORM_TEAM_ID} (0). Tenant company IDs always start
 * at 1, so the sentinel cannot collide with a real company.
 */
final class TenantContext
{
    public const PLATFORM_TEAM_ID = 0;

    private ?int $companyId = null;

    public function set(Company|int|null $company): void
    {
        if ($company === null) {
            $this->companyId = null;

            return;
        }

        $this->companyId = $company instanceof Company ? (int) $company->getKey() : $company;
    }

    public function id(): ?int
    {
        return $this->companyId;
    }

    public function has(): bool
    {
        return $this->companyId !== null;
    }

    public function forget(): void
    {
        $this->companyId = null;
    }

    /**
     * Spatie permission team id for the current context. Always returns a
     * non-null int because Spatie's pivot tables forbid NULL team_id. Platform
     * admin scope returns the {@see self::PLATFORM_TEAM_ID} sentinel.
     */
    public function permissionTeamId(): int
    {
        return $this->companyId ?? self::PLATFORM_TEAM_ID;
    }

    /**
     * Temporarily switch to a given company for the duration of a closure.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Company|int|null $company, Closure $callback): mixed
    {
        $previous = $this->companyId;
        $this->set($company);

        try {
            return $callback();
        } finally {
            $this->companyId = $previous;
        }
    }
}
