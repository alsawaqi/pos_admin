<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class OwnerProfileData extends Data
{
    public function __construct(
        public readonly string $fullNameEn,
        public readonly ?string $fullNameAr = null,
        public readonly ?string $civilId = null,
        public readonly ?string $nationality = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
    ) {}
}
