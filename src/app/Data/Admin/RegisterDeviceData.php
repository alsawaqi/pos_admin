<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\DeviceType;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Validated payload for registering a new device against the admin
 * portal (blueprint §4.4.2).
 *
 * The {@see MapName} attribute teaches spatie/laravel-data to accept
 * snake_case keys from the JSON request (`serial_number`,
 * `device_type`) and surface them as camelCase properties on the DTO
 * (`serialNumber`, `deviceType`).
 *
 * companyId + branchId are intentionally still here even though §4.4
 * splits Register and Assign into two separate admin pages. Keeping
 * the optional pre-assignment in the same DTO lets older tests
 * (AdminFoundationTest) and integration scripts continue to register
 * AND assign in a single call. The new "Register" admin page omits
 * them; the "Assign" page uses {@see AssignDeviceData}.
 */
#[MapName(SnakeCaseMapper::class)]
final class RegisterDeviceData extends Data
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly string $serialNumber,

        // The scalefusion kiosk id — REQUIRED in production because the
        // POS app pairs by reading it from the device at first boot.
        // Optional here only so factory-driven tests can omit it; the
        // FormRequest will demand it for real API calls.
        public readonly ?string $kioskId = null,

        // FK into commission_profiles. Required at registration —
        // the donation split needs a profile attached. (terminal_id +
        // bank_id are NOT here — they move to AssignDeviceData, captured
        // when the device is assigned to a merchant.)
        public readonly ?int $commissionProfileId = null,

        public readonly ?string $name = null,
        public readonly ?string $label = null,

        // Catalogue FKs — replaces the old free-text `model` string.
        // Optional in the DTO so legacy tests can omit them; the
        // FormRequest enforces presence + that the chosen model
        // actually belongs to the chosen make.
        public readonly ?int $makeId = null,
        public readonly ?int $modelId = null,

        // Defaults to FixedPos — the most common device class — so the
        // existing call sites that pre-date the explicit enum still
        // get a sensible value.
        public readonly DeviceType $deviceType = DeviceType::FixedPos,

        // Optional immediate assignment. When both are NULL the device
        // lands with status=registered awaiting assignment.
        public readonly ?int $companyId = null,
        public readonly ?int $branchId = null,

        public readonly ?string $appVersion = null,
        public readonly ?string $firmwareVersion = null,
        public readonly ?array $metadata = null,
    ) {}
}
