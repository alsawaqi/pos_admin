<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\CompanyStatus;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class TransitionCompanyStatusData extends Data
{
    public function __construct(
        public readonly CompanyStatus $targetStatus,
        public readonly ?string $reason = null,
    ) {}
}
