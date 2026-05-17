<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
final class UpdateCompanyData extends Data
{
    /**
     * @param  array<string, mixed>|Optional  $settings
     */
    public function __construct(
        public readonly string|Optional $name,
        public readonly string|null|Optional $nameAr,
        public readonly string|null|Optional $legalName,
        public readonly string|null|Optional $legalNameAr,
        public readonly CompanyComplianceData|Optional $compliance,
        public readonly CompanyContactData|Optional $contact,
        public readonly OwnerProfileData|Optional $owner,
        public readonly string|Optional $defaultCurrency,
        public readonly string|Optional $defaultLocale,
        public readonly array|Optional $settings,
        public readonly string|null|Optional $notes,
    ) {}
}
