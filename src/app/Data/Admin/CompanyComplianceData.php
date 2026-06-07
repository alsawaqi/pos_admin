<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class CompanyComplianceData extends Data
{
    public function __construct(
        public readonly string $crNumber,
        public readonly ?string $crIssueDate = null,
        public readonly ?string $crExpiryDate = null,
        public readonly ?string $establishmentDate = null,
        public readonly ?string $taxNumber = null,
        public readonly ?string $vatNumber = null,
        public readonly ?string $vatRegisteredAt = null,
        public readonly ?string $chamberOfCommerceNumber = null,
        public readonly ?string $municipalityLicenseNumber = null,
    ) {}
}
