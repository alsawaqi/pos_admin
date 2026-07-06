<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Validated payload for the Assign Device page (blueprint §4.4.3).
 *
 * Assignment is a separate admin action from registration: a device
 * may exist in our DB long before MITHQAL knows where it will be
 * shipped. The admin opens the Assign page, picks a company → branch
 * (the branch dropdown is filtered by company on the front-end),
 * confirms the branch's geo-fence radius is what they expect, and
 * submits.
 *
 * Assignment also captures the soft-POS terminal binding — `bankId` +
 * `terminalId` — because the terminal is issued against the merchant's
 * bank account, so it is only known once the device is assigned to a
 * merchant. (These moved here from RegisterDeviceData.)
 *
 * `geofenceRadiusM` is OPTIONAL because the source of truth lives on
 * the branch row — assignment usually inherits it as-is. If the
 * admin overrides it here, AssignDeviceAction will push the override
 * down to the branch (per §4.4.3: "Confirm geo-fence radius for this
 * assignment").
 */
#[MapName(SnakeCaseMapper::class)]
final class AssignDeviceData extends Data
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $branchId,
        public readonly int $bankId,
        public readonly string $terminalId,
        // Mosambee Soft-POS login PIN, issued by the bank alongside
        // the terminal_id. OPTIONAL (defaults to null so payloads
        // pre-dating the field still map) — devices without one fall
        // back to the vendor default PIN.
        public readonly ?string $terminalPin = null,
        public readonly ?int $geofenceRadiusM = null,
    ) {}
}
