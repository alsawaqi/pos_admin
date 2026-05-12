<?php

declare(strict_types=1);

namespace App\ValueObjects\Auth;

use Illuminate\Support\Carbon;

final readonly class IssuedJwt
{
    public function __construct(
        public string $accessToken,
        public Carbon $expiresAt,
    ) {}
}
