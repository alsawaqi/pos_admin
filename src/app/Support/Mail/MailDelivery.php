<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * LAUNCH-P1 P1-3 — "is real email delivery configured?"
 *
 * Mail works by configuration only: once the owner sets MAIL_MAILER=smtp
 * (plus host, port, user, password) emails go out. Until then the
 * `log` / `array` mailers would only write the message — including a
 * live set-password link — into a log, so links are NOT mailed and the
 * admin uses the "Copy set-password link" button instead.
 */
final class MailDelivery
{
    private const NON_DELIVERING = ['log', 'array'];

    public static function configured(): bool
    {
        $mailer = config('mail.default');

        return is_string($mailer) && $mailer !== '' && ! in_array($mailer, self::NON_DELIVERING, true);
    }
}
