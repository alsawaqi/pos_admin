<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = app(TenantContext::class)->id();

        if ($companyId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $companyId);
    }
}
