<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\BranchStatus;
use Spatie\LaravelData\Data;

final class CreateBranchData extends Data
{
    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $name,
        public readonly ?string $code = null,
        public readonly ?string $managerName = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $address = null,
        public readonly ?int $countryId = null,
        public readonly ?int $regionId = null,
        public readonly ?int $districtId = null,
        public readonly ?int $cityId = null,
        public readonly ?string $latitude = null,
        public readonly ?string $longitude = null,
        public readonly BranchStatus $status = BranchStatus::Active,
        public readonly ?array $settings = null,
    ) {}
}
