<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

trait HasCompanyScope
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        return $query->where($this->qualifyColumn('company_id'), $companyId);
    }
}
