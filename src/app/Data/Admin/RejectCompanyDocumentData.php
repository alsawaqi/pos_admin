<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Spatie\LaravelData\Data;

final class RejectCompanyDocumentData extends Data
{
    public function __construct(
        public readonly string $reason,
    ) {}
}
