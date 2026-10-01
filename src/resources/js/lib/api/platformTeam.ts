/**
 * Typed client for the Platform Team endpoints.
 *
 * Mirrors {@link \App\Http\Controllers\Api\Admin\PlatformTeamController}.
 * Auth happens server-side via direct permission checks
 * (PlatformUsers*) — no policy involved.
 *
 * LAUNCH-P1 P1-8: inviting an admin (and "send reset link") returns a
 * one-time `set_password_link`, never a password. Show it in the
 * "Copy set-password link" dialog and forget it.
 */

import { apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';
import type { PaginationLinks, PaginationMeta } from '@/lib/api/merchants';
import type { SetPasswordLink } from '@/lib/api/setPasswordLink';

/** Status enum — mirrors {@see \App\Enums\UserStatus}. */
export type PlatformUserStatus = 'active' | 'inactive' | 'suspended';

/** Role identifiers — mirrors {@see \App\Enums\PlatformRole}. */
export type PlatformRoleName =
    | 'platform_super_admin'
    | 'onboarding_officer'
    | 'device_operations'
    | 'support'
    | 'finance_viewer';

export interface PlatformUser {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: PlatformUserStatus | null;
    user_type: 'platform_admin' | 'merchant' | null;
    role: PlatformRoleName | null;
    last_login_at: string | null;
    invited_at: string | null;
    invited_by_admin_id: number | null;
    /** P1-8: the admin has chosen a password. */
    password_set?: boolean;
    /** P1-15: the admin's authenticator is set up. */
    two_factor_enabled?: boolean;
    set_password_link_expires_at?: string | null;
    created_at: string | null;
}

export interface PaginatedPlatformTeam {
    data: PlatformUser[];
    meta: PaginationMeta;
    links: PaginationLinks;
}

export interface InvitePlatformUserPayload {
    name: string;
    email: string;
    phone?: string | null;
    role: PlatformRoleName;
}

export interface InvitePlatformUserResponse {
    data: PlatformUser;
    /** Single-use set-password link. Surface ONCE, then forget. */
    set_password_link: SetPasswordLink;
}

/**
 * POST /platform-team/{id}/set-password-link — resend the invite (72 h)
 * to an admin who never set a password, or send a 60-minute reset link
 * (which also signs that admin out everywhere).
 */
export function sendPlatformUserPasswordLink(id: number): Promise<InvitePlatformUserResponse> {
    return apiPost<InvitePlatformUserResponse>(`/admin/api/v1/platform-team/${id}/set-password-link`);
}

/**
 * POST /platform-team/{id}/reset-two-factor — Super Admin only. Clears a
 * lost authenticator; the admin sets up a new one at the next sign-in.
 */
export function resetPlatformUserTwoFactor(id: number, reason: string): Promise<{ data: PlatformUser }> {
    return apiPost<{ data: PlatformUser }>(
        `/admin/api/v1/platform-team/${id}/reset-two-factor`,
        { confirm: true, reason },
    );
}

export interface UpdatePlatformUserPayload {
    name?: string;
    phone?: string | null;
    role?: PlatformRoleName;
}

export interface PlatformTeamQuery {
    page?: number;
    per_page?: number;
    search?: string;
    status?: PlatformUserStatus;
    [key: string]: string | number | boolean | null | undefined;
}

export function listPlatformTeam(query: PlatformTeamQuery = {}): Promise<PaginatedPlatformTeam> {
    return apiGet<PaginatedPlatformTeam>('/admin/api/v1/platform-team', { query });
}

export function invitePlatformUser(payload: InvitePlatformUserPayload): Promise<InvitePlatformUserResponse> {
    return apiPost<InvitePlatformUserResponse>(
        '/admin/api/v1/platform-team',
        payload as unknown as JsonValue,
    );
}

export function updatePlatformUser(
    id: number,
    payload: UpdatePlatformUserPayload,
): Promise<{ data: PlatformUser }> {
    return apiPatch<{ data: PlatformUser }>(
        `/admin/api/v1/platform-team/${id}`,
        payload as unknown as JsonValue,
    );
}

export function suspendPlatformUser(id: number): Promise<{ data: PlatformUser }> {
    return apiPost<{ data: PlatformUser }>(
        `/admin/api/v1/platform-team/${id}/suspend`,
    );
}

export function reactivatePlatformUser(id: number): Promise<{ data: PlatformUser }> {
    return apiPost<{ data: PlatformUser }>(
        `/admin/api/v1/platform-team/${id}/reactivate`,
    );
}
