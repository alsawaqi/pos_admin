<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Carbon\CarbonInterface;

/**
 * A freshly issued set-password link (LAUNCH-P1 P1-2 / P1-8).
 *
 * The URL carries the RAW token. It is returned once, to the admin who
 * issued it (the "Copy set-password link" button), and is never stored:
 * the database keeps only the token's SHA-256 hash.
 */
final readonly class SetPasswordLink
{
    public function __construct(
        public string $url,
        public CarbonInterface $expiresAt,
        public string $purpose,
        public bool $emailed,
        public bool $mailConfigured,
        public ?string $emailError = null,
    ) {}

    /**
     * @return array{url: string, expires_at: string, purpose: string, emailed: bool, mail_configured: bool, email_error: string|null}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'purpose' => $this->purpose,
            'emailed' => $this->emailed,
            'mail_configured' => $this->mailConfigured,
            'email_error' => $this->emailError,
        ];
    }
}
