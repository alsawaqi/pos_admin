<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\CompanyStatus;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class CreateCompanyData extends Data
{
    /**
     * @param  DataCollection<int, CompanyActivitySelectionData>|null  $activities
     * @param  array<string, mixed>|null  $settings
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $nameAr,
        public readonly ?string $legalName,
        public readonly ?string $legalNameAr,
        public readonly CompanyComplianceData $compliance,
        public readonly CompanyContactData $contact,
        public readonly OwnerProfileData $owner,
        #[DataCollectionOf(CompanyActivitySelectionData::class)]
        public readonly ?DataCollection $activities = null,
        public readonly string $defaultCurrency = 'OMR',
        public readonly string $defaultLocale = 'en',
        public readonly CompanyStatus $status = CompanyStatus::Onboarding,
        public readonly ?array $settings = null,
        public readonly ?string $notes = null,
    ) {}
}
