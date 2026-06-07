<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\BranchOrderType;
use App\Enums\BranchStatus;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class CreateBranchData extends Data
{
    /**
     * @param  array<int|string, mixed>|null  $openingHoursJson
     * @param  array<string, mixed>|null  $settings
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $name,
        public readonly ?string $nameAr = null,
        public readonly ?string $code = null,
        public readonly ?string $managerName = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $address = null,
        public readonly ?int $countryId = null,
        public readonly ?int $regionId = null,
        public readonly ?int $districtId = null,
        public readonly ?int $cityId = null,
        public readonly float|string|null $latitude = null,
        public readonly float|string|null $longitude = null,
        public readonly int $geofenceRadiusM = 500,
        public readonly ?array $openingHoursJson = null,
        public readonly BranchOrderType $defaultOrderType = BranchOrderType::Quick,
        public readonly BranchStatus $status = BranchStatus::Active,
        public readonly ?array $settings = null,
    ) {}
}
