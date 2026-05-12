<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Spatie\LaravelData\Data;

final class RegisterDeviceData extends Data
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly string $serialNumber,
        public readonly ?string $name = null,
        public readonly string $deviceType = 'pos_terminal',
        public readonly ?int $companyId = null,
        public readonly ?int $branchId = null,
        public readonly ?string $appVersion = null,
        public readonly ?string $firmwareVersion = null,
        public readonly ?array $metadata = null,
    ) {}
}
