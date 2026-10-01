<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserType;
use App\Mail\SetPasswordLinkMail;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\Auth\SetPasswordLink;
use App\Support\Mail\MailDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Issue a single-use set-password link (LAUNCH-P1 P1-2 / P1-8; owner
 * decision 2026-09-29 1a "no hand-given passwords").
 *
 *   invite — a new merchant portal user or a new admin: 72 hours
 *   reset  — an admin-issued reset:                      60 minutes
 *   forgot — the admin portal's own forgot-password:     60 minutes
 *
 * An admin-issued link kills every older unused link of the user, so
 * only the newest works ("resend" = issue again). A forgot-password link
 * only replaces older forgot links — it never revokes an admin's link.
 * The token is 64 random chars; only its SHA-256 hash is stored. The
 * link points at the portal the user belongs to (merchant portal or
 * admin portal).
 *
 * Delivery: the link is emailed when real mail is configured
 * ({@see MailDelivery}); a mail failure is reported and never breaks the
 * request. The caller gets the raw link back exactly once so an admin
 * can copy it (WhatsApp) — the "Copy set-password link" button.
 *
 * Call this OUTSIDE (after) any surrounding DB transaction: the email
 * must not leave before the account it points at is committed.
 */
final readonly class IssueSetPasswordLinkAction
{
    public const INVITE_TTL_MINUTES = 72 * 60;

    public const RESET_TTL_MINUTES = 60;

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(User $user, string $purpose, ?User $actor = null): SetPasswordLink
    {
        if (! in_array($purpose, [PasswordResetToken::PURPOSE_INVITE, PasswordResetToken::PURPOSE_RESET, PasswordResetToken::PURPOSE_FORGOT], true)) {
            throw new InvalidArgumentException("Unknown set-password link purpose [{$purpose}].");
        }

        $rawToken = Str::random(64);
        $expiresAt = now()->addMinutes(
            $purpose === PasswordResetToken::PURPOSE_INVITE ? self::INVITE_TTL_MINUTES : self::RESET_TTL_MINUTES,
        );

        DB::transaction(function () use ($user, $purpose, $actor, $rawToken, $expiresAt): void {
            // An admin-issued link (invite / reset) supersedes every older
            // unused link of the user. A self-service forgot-password
            // request only replaces older FORGOT links: anyone can type an
            // email, so it must never revoke a link an admin issued
            // (review finding).
            PasswordResetToken::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->when(
                    $purpose === PasswordResetToken::PURPOSE_FORGOT,
                    fn ($query) => $query->where('purpose', PasswordResetToken::PURPOSE_FORGOT),
                )
                ->delete();

            PasswordResetToken::query()->create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $rawToken),
                'purpose' => $purpose,
                'issued_by_user_id' => $actor?->id,
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);
        });

        $url = $this->url($user, $purpose, $rawToken);
        $mailConfigured = MailDelivery::configured();
        $emailed = false;
        $emailError = null;

        if ($mailConfigured) {
            try {
                Mail::to((string) $user->email)->send(new SetPasswordLinkMail(
                    recipient: $user,
                    url: $url,
                    expiresAt: $expiresAt,
                    purpose: $purpose,
                    portalName: $this->portalName($user),
                    companyName: $this->isMerchant($user) ? $user->company?->name : null,
                ));
                $emailed = true;
            } catch (Throwable $e) {
                // P1-3: a mail failure is logged and never breaks the
                // request — the admin still gets the link to copy.
                report($e);
                $emailError = 'The email could not be sent. Copy the link and send it to the user another way.';
            }
        } else {
            Log::info('Set-password link not emailed: no mail transport is configured (MAIL_MAILER).', [
                'user_id' => $user->id,
                'purpose' => $purpose,
            ]);
        }

        $this->writeAuditLog->handle(new AuditLogData(
            event: ($this->isMerchant($user) ? 'portal_user' : 'platform_user').'.set_password_link_issued',
            actorUserId: $actor?->id,
            companyId: $user->company_id === null ? null : (int) $user->company_id,
            auditableType: User::class,
            auditableId: (int) $user->id,
            // Never the token or the link — only the fact and its shape.
            newValues: [
                'purpose' => $purpose,
                'expires_at' => $expiresAt->toIso8601String(),
                'emailed' => $emailed,
            ],
            metadata: [
                'mail_configured' => $mailConfigured,
                'mail_failed' => $emailError !== null,
            ],
        ));

        return new SetPasswordLink(
            url: $url,
            expiresAt: $expiresAt,
            purpose: $purpose,
            emailed: $emailed,
            mailConfigured: $mailConfigured,
            emailError: $emailError,
        );
    }

    /**
     * Which link to (re)send to a user who has NO usable password: a
     * user who was reset (or used forgot-password) keeps getting 60-minute
     * reset links; a user who was only ever invited gets the 72-hour
     * invite again.
     */
    public static function purposeForUserWithoutPassword(User $user): string
    {
        $latest = PasswordResetToken::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->value('purpose');

        return in_array($latest, [PasswordResetToken::PURPOSE_RESET, PasswordResetToken::PURPOSE_FORGOT], true)
            ? PasswordResetToken::PURPOSE_RESET
            : PasswordResetToken::PURPOSE_INVITE;
    }

    private function isMerchant(User $user): bool
    {
        return $user->user_type === UserType::Merchant;
    }

    private function portalName(User $user): string
    {
        return $this->isMerchant($user) ? 'MITHQAL Merchant Portal' : 'MITHQAL POS Admin';
    }

    private function url(User $user, string $purpose, string $rawToken): string
    {
        if ($this->isMerchant($user)) {
            $base = rtrim((string) config('app.merchant_portal_url'), '/');
            $path = $purpose === PasswordResetToken::PURPOSE_INVITE ? '/setup-password' : '/reset-password';
        } else {
            $base = rtrim((string) config('app.url'), '/');
            $path = $purpose === PasswordResetToken::PURPOSE_INVITE ? '/set-password' : '/reset-password';
        }

        return $base.$path.'?'.http_build_query([
            'token' => $rawToken,
            'email' => (string) $user->email,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
