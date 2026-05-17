<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns only the active tenant\'s branches when the context is set', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Branch::factory()->count(2)->for($companyA)->create();
    Branch::factory()->count(3)->for($companyB)->create();

    /** @var TenantContext $tenant */
    $tenant = app(TenantContext::class);
    $tenant->set($companyA);

    expect(Branch::query()->count())->toBe(2);

    $tenant->set($companyB);
    expect(Branch::query()->count())->toBe(3);

    $tenant->forget();
    expect(Branch::query()->count())->toBe(5);
});

it('auto-stamps company_id on create when a tenant is active', function (): void {
    $company = Company::factory()->create();

    app(TenantContext::class)->run($company, function () use ($company): void {
        $branch = Branch::factory()->create([
            'company_id' => null,
            'name' => 'Implicit Tenant Branch',
        ]);

        expect($branch->company_id)->toBe($company->id);
    });
});

it('exposes withoutTenantScope to fetch all rows when needed', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    Branch::factory()->for($companyA)->create();
    Branch::factory()->for($companyB)->create();

    app(TenantContext::class)->run($companyA, function (): void {
        expect(Branch::query()->count())->toBe(1);
        expect(Branch::query()->withoutGlobalScope(TenantScope::class)->count())->toBe(2);
    });
});

it('isolates devices the same way as branches', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    Device::factory()->count(2)->create(['company_id' => $companyA->id]);
    Device::factory()->count(4)->create(['company_id' => $companyB->id]);

    app(TenantContext::class)->run($companyA, function (): void {
        expect(Device::query()->count())->toBe(2);
    });

    app(TenantContext::class)->run($companyB, function (): void {
        expect(Device::query()->count())->toBe(4);
    });
});

it('restores the previous tenant after run() completes', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $tenant = app(TenantContext::class);

    $tenant->set($companyA);

    $tenant->run($companyB, function () use ($tenant, $companyB): void {
        expect($tenant->id())->toBe($companyB->id);
    });

    expect($tenant->id())->toBe($companyA->id);
});
