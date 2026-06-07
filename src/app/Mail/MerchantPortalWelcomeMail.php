<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Welcome email dispatched to a freshly-invited merchant portal
 * user. The raw setup token is embedded in the link — this is the
 * ONLY place the raw token ever appears (the database stores only
 * its SHA-256 hash).
 *
 * The link points at the merchant portal (port 8087 in dev). The
 * /setup-password endpoint that consumes the token lives on the
 * pos_merchant side and gets built in Sprint 4 — until then the
 * link goes nowhere useful, but the email + invite flow works
 * end-to-end today so we can demo it.
 */
class MerchantPortalWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly Company $company,
        // Raw, un-hashed setup token. Lives only in memory + this
        // email body — never stored anywhere queryable.
        public readonly string $setupToken,
        // Wall-clock expiry shown in the email body so the recipient
        // knows how long they have to click.
        public readonly \DateTimeInterface $expiresAt,
    ) {}

    /**
     * Build the link the recipient clicks. The URL points at the
     * merchant portal's /setup-password endpoint with the token + the
     * email as query params. The setup endpoint hashes the incoming
     * token and matches against pos_users.setup_token_hash.
     */
    public function setupUrl(): string
    {
        $base = rtrim((string) config('app.merchant_portal_url', 'http://localhost:8087'), '/');

        return $base.'/setup-password?token='.urlencode($this->setupToken).'&email='.urlencode($this->recipient->email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to '.$this->company->name.' on MITHQAL POS',
            to: [$this->recipient->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.merchant-portal-welcome',
            with: [
                'recipientName' => $this->recipient->name,
                'companyName' => $this->company->name,
                'setupUrl' => $this->setupUrl(),
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
