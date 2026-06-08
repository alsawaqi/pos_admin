<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\DeviceType;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * Validated payload for editing a device (PATCH). Every property is `Optional`
 * so an absent key means "leave it unchanged" — {@see UpdateDeviceAction}
 * filters the Optionals out before filling the model (mirrors UpdateBranchData).
 */
#[MapName(SnakeCaseMapper::class)]
final class UpdateDeviceData extends Data
{
    public function __construct(
        public readonly string|Optional $serialNumber,
        public readonly string|Optional $kioskId,
        public readonly int|Optional $commissionProfileId,
        public readonly int|Optional $organizationId,
        public readonly string|null|Optional $name,
        public readonly string|null|Optional $label,
        public readonly int|Optional $makeId,
        public readonly int|Optional $modelId,
        public readonly DeviceType|Optional $deviceType,
    ) {}
}
