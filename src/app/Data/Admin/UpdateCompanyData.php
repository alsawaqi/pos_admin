<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * Validated payload for PATCH /admin/api/v1/merchants/{uuid}.
 *
 * Every property is `Optional` so the caller can send only the
 * fields they want to change. When `owners` IS sent, it REPLACES
 * the entire owners list — sync semantics, not patch semantics, so
 * removing an owner is just leaving them out of the array on the
 * next update.
 */
#[MapName(SnakeCaseMapper::class)]
final class UpdateCompanyData extends Data
{
    /**
     * @param  DataCollection<int, CompanyOwnerData>|Optional  $owners
     * @param  array<string, mixed>|Optional  $settings
     */
    public function __construct(
        public readonly string|Optional $name,
        public readonly string|null|Optional $nameAr,
        public readonly string|null|Optional $legalName,
        public readonly string|null|Optional $legalNameAr,
        public readonly CompanyComplianceData|Optional $compliance,
        public readonly CompanyContactData|Optional $contact,
        #[DataCollectionOf(CompanyOwnerData::class)]
        public readonly DataCollection|Optional $owners,
        public readonly string|Optional $defaultCurrency,
        public readonly string|Optional $defaultLocale,
        public readonly array|Optional $settings,
        public readonly string|null|Optional $notes,
    ) {}
}
