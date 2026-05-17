<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Company;
use App\Models\CompanyDocument;
use App\Policies\CompanyDocumentPolicy;
use App\Policies\CompanyPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Company::class => CompanyPolicy::class,
        CompanyDocument::class => CompanyDocumentPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
