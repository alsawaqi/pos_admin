<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\CompanyStatus;
use Spatie\LaravelData\Data;

final class CreateCompanyData extends Data
{
    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $legalName = null,
        public readonly ?string $commercialRegistrationNumber = null,
        public readonly ?string $taxNumber = null,
        public readonly ?string $contactName = null,
        public readonly ?string $contactPhone = null,
        public readonly ?string $contactEmail = null,
        public readonly CompanyStatus $status = CompanyStatus::Onboarding,
        public readonly ?array $settings = null,
        public readonly ?string $notes = null,
    ) {}
}
