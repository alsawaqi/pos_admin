/**
 * TypeScript client for the Merchant Portal Users admin endpoints
 * (blueprint §4.5). Mirrors PortalUserResource shape from the
 * back-end — keep both in sync or vue-tsc will flag the drift.
 *
 * Flow changed from "invite by email" to "create with password":
 * the admin enters name+email, the server generates a one-time
 * password, the response includes it (plaintext, ONCE), the admin
 * shares it with the merchant out of band. No setup-link mailer.
 *
 * Endpoints (all nested under /admin/api/v1/merchants/{uuid}):
 *   GET    /portal-users                              → listPortalUsers
 *   POST   /portal-users                              → createPortalUser
 *   PATCH  /portal-users/{id}                         → updatePortalUser
 *   POST   /portal-users/{id}/reset-password          → resetPortalUserPassword
 */

import { apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

/**
 * Lifecycle status for a portal user. Matches the UserStatus enum
 * on the back-end. With the create-with-password flow, new users
 * land directly in `active` — `inactive` is now reserved for
 * users that were created and then explicitly deactivated by an
 * admin.
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
    /**
     * Legacy field from the invite-by-email era. With the
     * create-with-password flow this is always `false` for new
     * users; old rows that never completed setup may still be
     * `true` until the admin runs reset-password against them.
     */
    setup_pending: boolean;
    setup_token_expires_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface CreateMerchantUserPayload {
    name: string;
    email: string;
    phone?: string | null;
}

/**
 * Response envelope for create + reset-password. The plaintext
 * password is intentionally OUTSIDE the `data` object so the
 * frontend has to consciously handle it (vs accidentally
 * persisting it alongside other user fields).
 */
export interface PortalUserWithPasswordResponse {
    data: PortalUser;
    /** Generated server-side. Surface in a one-shot modal then forget. */
    plaintext_password: string;
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
 * POST /portal-users — create the initial merchant admin user.
 * Server generates the password and returns it in plaintext ONCE.
 * Refused with 422 if the merchant has no branches or no devices
 * (blueprint §4.5 gate).
 */
export function createPortalUser(
    merchantUuid: string,
    payload: CreateMerchantUserPayload,
): Promise<PortalUserWithPasswordResponse> {
    return apiPost<PortalUserWithPasswordResponse>(
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
 * POST /portal-users/{id}/reset-password — generate a fresh
 * password and return the plaintext ONCE. Replaces the old
 * "resend invite" flow which only made sense for email-based
 * setup links.
 */
export function resetPortalUserPassword(
    merchantUuid: string,
    portalUserId: number,
): Promise<PortalUserWithPasswordResponse> {
    return apiPost<PortalUserWithPasswordResponse>(
        `/admin/api/v1/merchants/${merchantUuid}/portal-users/${portalUserId}/reset-password`,
    );
}
