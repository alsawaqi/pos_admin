<?php

declare(strict_types=1);

use App\Actions\Admin\TransitionCompanyStatusAction;
use App\Data\Admin\TransitionCompanyStatusData;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('moves a company from onboarding to active and stamps activated_at', function (): void {
    $company = Company::factory()->create(['status' => CompanyStatus::Onboarding]);

    $updated = app(TransitionCompanyStatusAction::class)->handle(
        $company,
        new TransitionCompanyStatusData(targetStatus: CompanyStatus::Active),
    );

    expect($updated->status)->toBe(CompanyStatus::Active)
        ->and($updated->activated_at)->not->toBeNull();

    $this->assertDatabaseHas(CompanyStatusHistory::class, [
        'company_id' => $company->id,
        'from_status' => CompanyStatus::Onboarding->value,
        'to_status' => CompanyStatus::Active->value,
    ]);
});

it('captures the suspension reason when transitioning to suspended', function (): void {
    $company = Company::factory()->active()->create();

    $updated = app(TransitionCompanyStatusAction::class)->handle(
        $company,
        new TransitionCompanyStatusData(targetStatus: CompanyStatus::Suspended, reason: 'VAT cert expired'),
    );

    expect($updated->status)->toBe(CompanyStatus::Suspended)
        ->and($updated->suspension_reason)->toBe('VAT cert expired')
        ->and($updated->suspended_at)->not->toBeNull();
});

it('refuses illegal transitions per the state machine', function (): void {
    $company = Company::factory()->create(['status' => CompanyStatus::Inactive]);

    expect(fn () => app(TransitionCompanyStatusAction::class)->handle(
        $company,
        new TransitionCompanyStatusData(targetStatus: CompanyStatus::Active),
    ))->toThrow(DomainException::class);
});

it('clears suspension fields when transitioning back to active', function (): void {
    $company = Company::factory()->suspended()->create();

    $updated = app(TransitionCompanyStatusAction::class)->handle(
        $company,
        new TransitionCompanyStatusData(targetStatus: CompanyStatus::Active, reason: 'Issue resolved'),
    );

    expect($updated->status)->toBe(CompanyStatus::Active)
        ->and($updated->suspended_at)->toBeNull()
        ->and($updated->suspension_reason)->toBeNull();
});
