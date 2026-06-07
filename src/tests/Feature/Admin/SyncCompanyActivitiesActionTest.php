<?php

declare(strict_types=1);

use App\Actions\Admin\SyncCompanyActivitiesAction;
use App\Data\Admin\CompanyActivitySelectionData;
use App\Models\BusinessActivity;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects more than one primary activity', function (): void {
    $company = Company::factory()->create();
    $a = BusinessActivity::factory()->create();
    $b = BusinessActivity::factory()->create();

    expect(fn () => app(SyncCompanyActivitiesAction::class)->handle($company, [
        new CompanyActivitySelectionData(businessActivityId: $a->id, isPrimary: true),
        new CompanyActivitySelectionData(businessActivityId: $b->id, isPrimary: true),
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects unknown business activity ids', function (): void {
    $company = Company::factory()->create();

    expect(fn () => app(SyncCompanyActivitiesAction::class)->handle($company, [
        new CompanyActivitySelectionData(businessActivityId: 9_999_999),
    ]))->toThrow(InvalidArgumentException::class);
});

it('replaces the previous selection on each sync', function (): void {
    $company = Company::factory()->create();
    $a = BusinessActivity::factory()->create();
    $b = BusinessActivity::factory()->create();
    $c = BusinessActivity::factory()->create();

    $action = app(SyncCompanyActivitiesAction::class);

    $action->handle($company, [
        new CompanyActivitySelectionData(businessActivityId: $a->id, isPrimary: true),
        new CompanyActivitySelectionData(businessActivityId: $b->id),
    ]);

    $action->handle($company, [
        new CompanyActivitySelectionData(businessActivityId: $c->id, isPrimary: true),
    ]);

    $company->load('activities');

    expect($company->activities->pluck('id')->all())->toBe([$c->id]);
});
