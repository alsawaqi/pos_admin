<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\BranchOrderType;
use App\Enums\BranchStatus;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
final class UpdateBranchData extends Data
{
    /**
     * @param  array<int|string, mixed>|null|Optional  $openingHoursJson
     * @param  array<string, mixed>|null|Optional  $settings
     */
    public function __construct(
        public readonly string|Optional $name,
        public readonly string|null|Optional $nameAr,
        public readonly string|null|Optional $code,
        public readonly string|null|Optional $managerName,
        public readonly string|null|Optional $phone,
        public readonly string|null|Optional $email,
        public readonly string|null|Optional $address,
        public readonly int|null|Optional $countryId,
        public readonly int|null|Optional $regionId,
        public readonly int|null|Optional $districtId,
        public readonly int|null|Optional $cityId,
        public readonly float|string|Optional $latitude,
        public readonly float|string|Optional $longitude,
        public readonly int|Optional $geofenceRadiusM,
        public readonly array|null|Optional $openingHoursJson,
        public readonly BranchOrderType|Optional $defaultOrderType,
        public readonly BranchStatus|Optional $status,
        public readonly array|null|Optional $settings,
    ) {}
}
