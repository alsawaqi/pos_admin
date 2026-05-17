<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\DocumentType;
use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class UploadCompanyDocumentData extends Data
{
    public function __construct(
        public readonly DocumentType $documentType,
        public readonly UploadedFile $file,
        public readonly ?string $issuedAt = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $notes = null,
    ) {}
}
