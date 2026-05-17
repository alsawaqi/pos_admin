<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opt-in trait that scopes a tenant-owned model by the currently active
 * company in {@see TenantContext}. Platform-admin contexts (no tenant) bypass
 * the scope, while merchant-facing requests are automatically isolated.
 *
 * Also auto-stamps `company_id` on create when a tenant is active so callers
 * cannot accidentally write a row to the wrong tenant.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (self $model): void {
            if ($model->getAttribute('company_id') !== null) {
                return;
            }

            $companyId = app(TenantContext::class)->id();

            if ($companyId !== null) {
                $model->setAttribute('company_id', $companyId);
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        return $query->withoutGlobalScope(TenantScope::class)
            ->where($this->qualifyColumn('company_id'), $companyId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
