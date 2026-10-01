/**
 * TypeScript client for the Merchant Portal Users admin endpoints
 * (blueprint §4.5). Mirrors PortalUserResource shape from the
 * back-end — keep both in sync or vue-tsc will flag the drift.
 *
 * LAUNCH-P1 P1-2 (owner decision: no hand-given passwords): creating a
 * login and "reset password" return a one-time set-password LINK
 * (`set_password_link`), never a password. The admin copies it (for
 * WhatsApp) or relies on the email when mail is configured.
 *
 * Endpoints (all nested under /admin/api/v1/merchants/{uuid}):
 *   GET    /portal-users                              → listPortalUsers
 *   POST   /portal-users                              → createPortalUser
 *   PATCH  /portal-users/{id}                         → updatePortalUser
 *   POST   /portal-users/{id}/reset-password          → resetPortalUserPassword
 */

import { apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';
import type { SetPasswordLink } from '@/lib/api/setPasswordLink';

export type { SetPasswordLink };

/**
 * Lifecycle status for a portal user. Matches the UserStatus enum
 * on the back-end.
 */
export type PortalUserStatus = 'inactive' | 'active' | 'suspended';

/** One row in the Portal Users tab of the Merchant Show page. */
export interface PortalUser {
    id: number;
    company_id: number;
    name: string;
    email: string;
    phone: string | null;
    user_type: 'platform_admin' | 'merchant' | null;
    status: PortalUserStatus | null;
    // null = "all branches" (the default for the merchant Super
    // Admin); number[] = restricted to specific branches.
    branch_scope: number[] | null;
    last_login_at: string | null;
    invited_at: string | null;
    invited_by_admin_id: number | null;
    /** The user has chosen a password. */
    password_set: boolean;
    /** Not yet — they still need to open their set-password link. */
    setup_pending: boolean;
    /** Expiry of the newest unused link (the link itself is shown once). */
    set_password_link_expires_at: string | null;
    set_password_link_purpose: 'invite' | 'reset' | 'forgot' | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface CreateMerchantUserPayload {
    name: string;
    email: string;
    phone?: string | null;
}

/** Response envelope for create + reset-password. */
export interface PortalUserWithLinkResponse {
    data: PortalUser;
    /** Shown once in the "Copy set-password link" dialog, then forgotten. */
    set_password_link: SetPasswordLink;
}

export interface UpdatePortalUserPayload {
    status?: PortalUserStatus;
    branch_scope?: number[] | null;
    phone?: string | null;
}

/** GET /portal-users — list every portal user for the merchant. */
export function listPortalUsers(merchantUuid: string): Promise<{ data: PortalUser[] }> {
    return apiGet<{ data: PortalUser[] }>(
        `/admin/api/v1/merchants/${merchantUuid}/portal-users`,
    );
}

/**
 * POST /portal-users — create a merchant login (no password) and get its
 * 72-hour set-password link. No branch or device is needed first
 * (LAUNCH-P1 P1-14).
 */
export function createPortalUser(
    merchantUuid: string,
    payload: CreateMerchantUserPayload,
): Promise<PortalUserWithLinkResponse> {
    return apiPost<PortalUserWithLinkResponse>(
        `/admin/api/v1/merchants/${merchantUuid}/portal-users`,
        payload as unknown as JsonValue,
    );
}

/** PATCH /portal-users/{id} — change status / scope / phone. */
export function updatePortalUser(
    merchantUuid: string,
    portalUserId: number,
    payload: UpdatePortalUserPayload,
): Promise<{ data: PortalUser }> {
    return apiPatch<{ data: PortalUser }>(
        `/admin/api/v1/merchants/${merchantUuid}/portal-users/${portalUserId}`,
        payload as unknown as JsonValue,
    );
}

/**
 * POST /portal-users/{id}/reset-password — send a set-password link: a
 * resent invite (72 h) if the user never set a password, otherwise a
 * 60-minute reset link that also signs the user out everywhere.
 */
export function resetPortalUserPassword(
    merchantUuid: string,
    portalUserId: number,
): Promise<PortalUserWithLinkResponse> {
    return apiPost<PortalUserWithLinkResponse>(
        `/admin/api/v1/merchants/${merchantUuid}/portal-users/${portalUserId}/reset-password`,
    );
}
