<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Set-password / reset-password email (LAUNCH-P1 P1-2, P1-8) for both
 * populations of pos_users: merchant portal users and platform admins.
 * Replaces the old MerchantPortalWelcomeMail, whose link pointed at a
 * page that never existed.
 *
 * Sent synchronously (never queued) so the issuing request knows at
 * once whether the email left; a failure is reported and the admin
 * falls back to the "Copy set-password link" button.
 */
class SetPasswordLinkMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly User $recipient,
        public readonly string $url,
        public readonly CarbonInterface $expiresAt,
        public readonly string $purpose,
        public readonly string $portalName,
        public readonly ?string $companyName = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->purpose === 'invite'
            ? 'Set your '.$this->portalName.' password'
            : 'Reset your '.$this->portalName.' password';

        return new Envelope(
            subject: $subject,
            to: [(string) $this->recipient->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.set-password-link',
            with: [
                'recipientName' => $this->recipient->name,
                'portalName' => $this->portalName,
                'companyName' => $this->companyName,
                'purpose' => $this->purpose,
                'url' => $this->url,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
