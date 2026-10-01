/**
 * LAUNCH-P1 P1-2 / P1-8 — a single-use set-password link returned ONCE
 * by the server when an admin creates a login (merchant portal user or
 * admin) or sends a reset. Mirrors App\Support\Auth\SetPasswordLink.
 *
 * The `url` carries the raw token: show it in the "Copy set-password
 * link" dialog, never store it, never log it.
 */
export interface SetPasswordLink {
    url: string;
    /** ISO-8601. Invite links last 72 hours, reset links 60 minutes. */
    expires_at: string;
    purpose: 'invite' | 'reset' | 'forgot';
    /** The link was emailed to the user. */
    emailed: boolean;
    /** Real mail delivery (SMTP) is configured on the server. */
    mail_configured: boolean;
    /** Set when sending was attempted and failed. */
    email_error: string | null;
}
