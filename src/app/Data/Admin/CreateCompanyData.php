<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\CompanyStatus;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Validated payload for POST /admin/api/v1/merchants.
 *
 * `owners` is now a collection (was a single object). The wizard
 * sends at least one entry, and exactly one must carry
 * `is_primary: true` — enforced by {@see \App\Http\Requests\Admin\StoreMerchantRequest}.
 */
final class CreateCompanyData extends Data
{
    /**
     * @param  DataCollection<int, CompanyActivitySelectionData>|null  $activities
     * @param  DataCollection<int, CompanyOwnerData>  $owners
     * @param  array<string, mixed>|null  $settings
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $nameAr,
        public readonly ?string $legalName,
        public readonly ?string $legalNameAr,
        public readonly CompanyComplianceData $compliance,
        public readonly CompanyContactData $contact,
        // Multiple owners (blueprint §4.2.2 extension). Always at
        // least one, with exactly one flagged is_primary.
        #[DataCollectionOf(CompanyOwnerData::class)]
        public readonly DataCollection $owners,
        #[DataCollectionOf(CompanyActivitySelectionData::class)]
        public readonly ?DataCollection $activities = null,
        public readonly string $defaultCurrency = 'OMR',
        public readonly string $defaultLocale = 'en',
        public readonly CompanyStatus $status = CompanyStatus::Onboarding,
        public readonly ?array $settings = null,
        public readonly ?string $notes = null,
    ) {}
}
