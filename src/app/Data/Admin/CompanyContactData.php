<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class CompanyContactData extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
    ) {}
}
